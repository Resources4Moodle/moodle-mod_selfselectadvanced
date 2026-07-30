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

/**
 * Scale scenario probe: 10,000 students exercising the workflow
 * scenarios through the REAL services and table classes, timing each
 * step and counting its database work.
 *
 * Seeding is deliberately raw (bulk inserts, one shared password
 * hash): the system under test is the plugin's behaviour ON a large
 * cohort, not account provisioning. Every measured step then goes
 * through the ordinary service or table code path.
 *
 * Usage (maintainer testbed):
 *   php docs/tools/scale_scenarios.php --students=10000 --groups=1900 \
 *       --guides=200 [--reset]
 *
 * Output: one "PROBE <label>: <seconds>s reads=<n> writes=<n>" line
 * per step; grep PROBE for the summary table.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\attributes\manager as attrmanager;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\guides;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\volunteering;

[$options] = cli_get_params([
    'students' => 10000,
    'guides' => 200,
    'groups' => 1900,
    'shortname' => 'SCALE10K',
    'reset' => false,
], []);

\core\session\manager::set_user(get_admin());
$CFG->noemailever = true;
raise_memory_limit(MEMORY_HUGE);
core_php_time_limit::raise();
set_debugging(DEBUG_DEVELOPER, true);

$probes = [];
/**
 * Time a step and count its database work.
 *
 * @param string $label the step label
 * @param callable $fn the step
 * @return mixed the step's return value
 */
function probe(string $label, callable $fn) {
    global $DB, $probes;
    $r0 = $DB->perf_get_reads();
    $w0 = $DB->perf_get_writes();
    $t0 = microtime(true);
    $result = $fn();
    $t = microtime(true) - $t0;
    $reads = $DB->perf_get_reads() - $r0;
    $writes = $DB->perf_get_writes() - $w0;
    $probes[] = [$label, $t, $reads, $writes];
    cli_writeln(sprintf('PROBE %-46s %8.2fs reads=%-6d writes=%d', $label, $t, $reads, $writes));

    return $result;
}

$shortname = (string) $options['shortname'];
$nstudents = (int) $options['students'];
$nguides = (int) $options['guides'];
$ngroups = (int) $options['groups'];

// Probe users are namespaced by shortname so runs never collide with
// each other or with older scale fixtures on the same testbed; reset
// removes the previous run's users outright (test instances only).
$prefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $shortname)) . 'u';
$existing = $DB->get_record('course', ['shortname' => $shortname]);
if ($options['reset']) {
    if ($existing) {
        delete_course($existing, false);
        cli_writeln("Deleted previous {$shortname}");
        $existing = null;
    }
    $stale = $DB->get_fieldset_select('user', 'id', 'username LIKE ?', [$prefix . '%']);
    foreach (array_chunk($stale, 1000) as $chunk) {
        [$insql, $params] = $DB->get_in_or_equal($chunk);
        $DB->delete_records_select('selfselectadvanced_userattr', "userid $insql", $params);
        $DB->delete_records_select('user', "id $insql", $params);
    }
    if ($stale) {
        cli_writeln('Deleted ' . count($stale) . ' previous probe users');
    }
}
if ($existing) {
    cli_error("Course {$shortname} exists; use --reset to rebuild.");
}

$course = create_course((object) [
    'fullname' => 'Scale scenarios 10k',
    'shortname' => $shortname,
    'category' => 1,
    'format' => 'topics',
]);
$context = context_course::instance($course->id);
cli_writeln("Course {$shortname} id {$course->id}");

// ---------------------------------------------------------------------
// Raw seeding (not the SUT): users, enrolments, attributes, guides.
$hash = password_hash('Scale#2026', PASSWORD_DEFAULT);
$now = time();
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
$manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);

$depts = ['SCOPE', 'SENSE', 'SMEC', 'SCE', 'SELECT', 'SCHEME', 'SBST'];
$subs = ['SCOPE' => ['BCE', 'BIT', 'BAI'], 'SENSE' => ['BEC'], 'SMEC' => ['BME'],
    'SCE' => ['BCL'], 'SELECT' => ['BEE'], 'SCHEME' => ['BCM'], 'SBST' => ['BBT']];

probe('seed: 10k users + enrolments + attributes', function () use (
    $DB,
    $CFG,
    $hash,
    $now,
    $nstudents,
    $nguides,
    $studentrole,
    $teacherrole,
    $manual,
    $context,
    $depts,
    $subs,
    $prefix
) {
    $firstids = [];
    for ($batchstart = 0; $batchstart < $nstudents + $nguides; $batchstart += 500) {
        $users = [];
        $top = min($batchstart + 500, $nstudents + $nguides);
        for ($i = $batchstart; $i < $top; $i++) {
            $users[] = (object) [
                'auth' => 'manual',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'username' => sprintf('%s%05d', $prefix, $i),
                'password' => $hash,
                'firstname' => 'Scale',
                'lastname' => sprintf('U%05d', $i),
                'email' => sprintf('%s%05d@example.com', $prefix, $i),
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }
        $DB->insert_records('user', $users);
    }
    $ids = $DB->get_fieldset_select('user', 'id', "username LIKE ?", [$prefix . '%']);
    sort($ids);

    $enrolments = [];
    $assignments = [];
    $attrs = [];
    foreach ($ids as $index => $userid) {
        $isguide = $index >= $nstudents;
        $enrolments[] = (object) [
            'enrolid' => $manual->id, 'userid' => $userid, 'status' => 0,
            'timestart' => 0, 'timeend' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ];
        $assignments[] = (object) [
            'roleid' => $isguide ? $teacherrole->id : $studentrole->id,
            'contextid' => $context->id, 'userid' => $userid,
            'timemodified' => $now, 'modifierid' => 2, 'component' => '', 'itemid' => 0, 'sortorder' => 0,
        ];
        if (!$isguide) {
            $dept = $depts[$index % count($depts)];
            $sub = $subs[$dept][$index % count($subs[$dept])];
            $attrs[] = (object) [
                'userid' => $userid, 'gender' => $index % 2 ? 'Female' : 'Male',
                'department' => $dept, 'subdepartment' => $sub,
                'mobile' => '9198' . sprintf('%08d', $index),
                'shareconsent' => $index % 3 === 0 ? 1 : 0,
                'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 2,
            ];
        }
    }
    foreach (array_chunk($enrolments, 1000) as $chunk) {
        $DB->insert_records('user_enrolments', $chunk);
    }
    foreach (array_chunk($assignments, 1000) as $chunk) {
        $DB->insert_records('role_assignments', $chunk);
    }
    foreach (array_chunk($attrs, 1000) as $chunk) {
        $DB->insert_records('selfselectadvanced_userattr', $chunk);
    }

    return $ids;
});
$ids = $DB->get_fieldset_select('user', 'id', "username LIKE ?", [$prefix . '%']);
sort($ids);
$studentids = array_slice($ids, 0, $nstudents);
$guideids = array_slice($ids, $nstudents, $nguides);
// Reserved invite candidates (2 x SCOPE + two distinct others; seeding
// index i means department i % 7): the group-fill loop below drains
// the department pools front-first, so the eventual groupless
// leftovers ALL share the last department and can never complete a
// compliant team - the invite probe needs candidates that can. These
// four are kept out of the pools and out of the leftover list.
$invitereserve = [(int) $studentids[0], (int) $studentids[7],
    (int) $studentids[1], (int) $studentids[2]];

// The activity: the demonstration-matrix shape at scale.
$module = $DB->get_record('modules', ['name' => 'selfselectadvanced'], '*', MUST_EXIST);
$moduleinfo = add_moduleinfo((object) [
    'modulename' => 'selfselectadvanced', 'module' => $module->id, 'course' => $course->id,
    'section' => 0, 'visible' => 1,
    'name' => 'Scale teams', 'intro' => '<p>Scale probe.</p>', 'introformat' => FORMAT_HTML,
    'grade' => 100, 'minsize' => 5, 'maxsize' => 5, 'maxlead' => 1, 'maxmembership' => 1,
    'guidemode' => 0, 'maxguided' => 12, 'guidewindow' => DAYSECS, 'guideautoapprove' => 0,
    // The harness measures the guide-led flows - volunteering,
    // interest, the assignment queue - which students-approach mode
    // closes on purpose, and which is the default for a new
    // activity since 1.17.0. The probes that measure the new mode
    // turn it on for themselves.
    'studentapproach' => 0,
    'guidevolunteer' => 1, 'eoienabled' => 1, 'eoiwindow' => DAYSECS, 'eoimax' => 10,
    'eoigroupmax' => 3, 'eoisequential' => 0, 'eoipeers' => 1,
    'timeopen' => 0, 'timedue' => 0, 'timecutoff' => 0, 'penaltytype' => 0, 'penaltyperday' => 0,
    'inviteexpiry' => 0, 'autogroup' => 0, 'proposalrequired' => 0, 'minmembership' => 0,
    'defaulterpenalty' => 0, 'incompletepenalty' => 0, 'leadershare' => 60,
], $course);
$activity = activity::from_instance((int) $moduleinfo->instance);
$cm = $activity->cm();
slots::create($activity, (object) ['mincount' => 2, 'dimension' => 'department',
    'matchtype' => 'value', 'value' => 'SCOPE', 'allowoverlap' => 0]);
slots::create($activity, (object) ['mincount' => 3, 'dimension' => 'department',
    'matchtype' => 'distinct', 'allowoverlap' => 0]);
foreach ($guideids as $index => $guideid) {
    volunteering::set($activity, (int) $guideid, 1 + ($index % 12));
}
cli_writeln("Activity cmid {$cm->id}");

// Bulk groups: ~$ngroups of five (9500 students), the rest groupless.
// Students were seeded round-robin across 7 departments, so taking
// them in seeded order per group of five yields the compliant mix
// (2 x SCOPE arrives via index arithmetic below).
probe("seed: {$ngroups} groups of five (raw)", function () use (
    $DB,
    $activity,
    $studentids,
    $ngroups,
    $now,
    $shortname,
    $invitereserve
) {
    $grouprows = [];
    for ($g = 0; $g < $ngroups; $g++) {
        $grouprows[] = (object) [
            'activityid' => $activity->id(),
            'pluginuid' => sprintf('SSA-%s-%05d', $shortname, $g + 1),
            'name' => sprintf('Scale team %04d', $g + 1),
            'title' => 'Scale topic',
            'brief' => '<p>b</p>', 'briefformat' => FORMAT_HTML,
            'leaderid' => 0, 'state' => 'forming', 'autoformed' => 0,
            'listed' => $g < 1500 ? 1 : 0, 'timelisted' => $g < 1500 ? $now - $g : null,
            'timecreated' => $now - 86400, 'timemodified' => $now,
        ];
    }
    $DB->insert_records('selfselectadvanced_group', $grouprows);
    $groupids = $DB->get_fieldset_select(
        'selfselectadvanced_group',
        'id',
        'activityid = ?',
        [$activity->id()]
    );
    sort($groupids);

    // Membership: five per group, composed from per-department pools
    // so no user repeats. While the SCOPE pool holds two, groups get
    // the compliant 2 x SCOPE + 3 distinct-others mix; once SCOPE runs
    // dry the remaining groups take five consecutive distinct-
    // department students (realistically non-compliant on the SCOPE
    // seats — the compliance sweeps must price those too).
    $pools = [];
    foreach ($studentids as $index => $userid) {
        // The reserve stays groupless; the index keeps its meaning
        // (index % 7 IS the department) for everyone else.
        if (in_array((int) $userid, $invitereserve, true)) {
            continue;
        }
        $pools[$index % 7][] = (int) $userid;
    }
    $others = [1, 2, 3, 4, 5, 6];
    $members = [];
    $rotation = 0;
    foreach ($groupids as $gindex => $groupid) {
        $five = [];
        if (count($pools[0]) >= 2) {
            $five[] = array_pop($pools[0]);
            $five[] = array_pop($pools[0]);
            for ($k = 0; $k < 3; $k++) {
                $dept = $others[($rotation + $k) % 6];
                if ($pools[$dept]) {
                    $five[] = array_pop($pools[$dept]);
                }
            }
            $rotation++;
        }
        while (count($five) < 5) {
            $before = count($five);
            foreach ($others as $dept) {
                if (count($five) >= 5) {
                    break;
                }
                if ($pools[$dept]) {
                    $five[] = array_pop($pools[$dept]);
                }
            }
            if (count($five) === $before) {
                // A full pass added nobody: the non-SCOPE pools are
                // dry. Checking the pools directly would count a
                // stranded odd SCOPE remainder (only ever drawn in
                // pairs) and spin forever.
                break;
            }
        }
        if (count($five) < 5) {
            break;
        }
        $leader = (int) $five[0];
        $DB->set_field('selfselectadvanced_group', 'leaderid', $leader, ['id' => $groupid]);
        foreach ($five as $mindex => $userid) {
            $members[] = (object) [
                'groupid' => $groupid, 'userid' => (int) $userid,
                'status' => 'confirmed', 'isleader' => $mindex === 0 ? 1 : 0,
                'invitedby' => $leader, 'timeinvited' => $now - 86400,
                'timeresponded' => $now - 80000 + $mindex,
                'timecreated' => $now - 86400, 'timemodified' => $now,
            ];
        }
        if (count($members) > 2000) {
            $DB->insert_records('selfselectadvanced_member', $members);
            $members = [];
        }
    }
    if ($members) {
        $DB->insert_records('selfselectadvanced_member', $members);
    }

    return count($groupids);
});

$groupids = $DB->get_fieldset_select('selfselectadvanced_group', 'id', 'activityid = ?', [$activity->id()]);
sort($groupids);
$listedids = array_slice($groupids, 0, 1500);

// Pending interests spread over listed teams (2 guides on many teams).
probe('seed: 2500 pending guide interests (raw)', function () use ($DB, $activity, $listedids, $guideids, $now) {
    $rows = [];
    foreach ($listedids as $index => $groupid) {
        for ($j = 0; $j < ($index % 3 === 0 ? 2 : 1) && count($rows) < 2500; $j++) {
            $rows[] = (object) [
                'activityid' => $activity->id(), 'groupid' => $groupid,
                'guideid' => (int) $guideids[($index + $j) % count($guideids)],
                'status' => 'pending', 'remarks' => '<p>r</p>', 'remarksformat' => FORMAT_HTML,
                'timecreated' => $now - $index, 'timeresponded' => null,
            ];
        }
    }
    $DB->insert_records('selfselectadvanced_eoi', $rows);

    return count($rows);
});

// ---------------------------------------------------------------------
// Measured scenarios: the real services and table classes.
$api = new api($activity);
$gatekeeper = $api->gatekeeper();
// Reserve users for the moves probe BEFORE the invite churn consumes
// the leftover pool (RCA-3). Ordered by userid: students were seeded
// round-robin across departments, so consecutive leftovers rotate
// departments and the invite probe meets feasible candidates.
$groupless = array_map('intval', $DB->get_fieldset_sql(
    "SELECT ua.userid FROM {selfselectadvanced_userattr} ua
      WHERE ua.userid IN (SELECT id FROM {user} WHERE username LIKE :pfx)
        AND NOT EXISTS (SELECT 1 FROM {selfselectadvanced_member} m
                          JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                         WHERE g.activityid = :aid AND m.userid = ua.userid)
   ORDER BY ua.userid",
    ['pfx' => $prefix . '%', 'aid' => $activity->id()]
));
$groupless = array_values(array_diff($groupless, $invitereserve));
$movesreserve = array_splice($groupless, 0, 25);
$freshleader = (int) array_shift($groupless);

$freshgroup = probe('service: create_group (cascade check inside)', function () use ($api, $freshleader) {
    return $api->create_group($freshleader, 'Probe team', 'T', '<p>b</p>', FORMAT_HTML);
});

probe('service: 10 gate refusals + 4 x invite + accept', function () use (
    $api,
    $activity,
    $freshgroup,
    $freshleader,
    $invitereserve,
    &$groupless,
    $DB
) {
    // Refusal pricing first, while seats remain: every leftover shares
    // the leader's department, so the admission gate must refuse each
    // one (composition unreachable) - the cheap path, priced 10 times.
    $refused = 0;
    for ($i = 0; $i < 10 && $groupless; $i++) {
        $candidate = (int) array_shift($groupless);
        $sent = false;
        try {
            $api->invitations()->send($freshgroup, $candidate, $freshleader);
            $sent = true;
        } catch (moodle_exception $e) {
            // The service threw from inside its transaction; roll it
            // back before continuing the probe loop.
            $DB->force_transaction_rollback();
            $refused++;
        }
        if ($sent) {
            throw new coding_exception('gate MUST refuse a same-department leftover');
        }
    }
    // Then the reserved compliant candidates: 2 x SCOPE + two distinct
    // others complete the leader's team, so all four accepts must
    // succeed - unguarded, a refusal here fails the probe loudly.
    $done = 0;
    foreach ($invitereserve as $candidate) {
        $api->invitations()->send($freshgroup, $candidate, $freshleader);
        $api->invitations()->accept($freshgroup, $candidate);
        $done++;
    }

    return "refused {$refused}, accepted {$done}";
});

probe('service: cascade - accept with 5 rival invites', function () use ($api, $activity, $DB, &$groupless, $groupids, $now) {
    $star = (int) array_shift($groupless);
    $stardept = $DB->get_field('selfselectadvanced_userattr', 'department', ['userid' => $star]);
    // Rivals come from the HEAD of the list — the seat-plan-compliant
    // groups — because a same-department swap is composition-neutral
    // only there; the tail groups are the intentionally non-compliant
    // ones the admission gate must keep refusing to fill.
    $rivals = array_slice($groupids, 0, 5);
    // Free a seat in the FIRST rival that holds a member of the star's
    // own department (so the acceptance stays composition-feasible);
    // the other rivals hold pending invitations that must cascade.
    $accepttarget = null;
    foreach ($rivals as $rgid) {
        $victim = $DB->get_record_sql(
            "SELECT m.* FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_userattr} a ON a.userid = m.userid
              WHERE m.groupid = :g AND m.isleader = 0 AND m.status = 'confirmed'
                AND a.department = :d",
            ['g' => $rgid, 'd' => $stardept],
            IGNORE_MULTIPLE
        );
        if ($victim && $accepttarget === null) {
            $DB->set_field('selfselectadvanced_member', 'status', 'removed', ['id' => $victim->id]);
            $accepttarget = (int) $rgid;
        }
    }
    if ($accepttarget === null) {
        return 'skipped: no department match among rivals';
    }
    $rows = [];
    foreach ($rivals as $rgid) {
        $rows[] = (object) [
            'groupid' => $rgid, 'userid' => $star, 'status' => 'invited', 'isleader' => 0,
            'invitedby' => 2, 'timeinvited' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ];
    }
    $DB->insert_records('selfselectadvanced_member', $rows);
    $group = groups::get($activity, $accepttarget);
    $api->invitations()->accept($group, $star);

    return true;
});

probe('service: 10 x eoi::express (groupmax + caps)', function () use ($activity, $listedids, $guideids, $DB) {
    $done = 0;
    for ($i = 0; $i < 10; $i++) {
        try {
            eoi::express(
                $activity,
                (int) $listedids[($i * 7) % count($listedids)],
                (int) $guideids[$i % count($guideids)],
                '<p>x</p>'
            );
            $done++;
        } catch (moodle_exception $e) {
            $DB->force_transaction_rollback();
            continue;
        }
    }

    return $done;
});

probe('service: 100 x eoi::queue_position', function () use ($DB, $activity) {
    $rows = $DB->get_records('selfselectadvanced_eoi', ['activityid' => $activity->id(),
        'status' => 'pending'], 'id ASC', 'id, groupid', 0, 100);
    foreach ($rows as $row) {
        eoi::queue_position($activity, (int) $row->groupid, (int) $row->id);
    }

    return count($rows);
});

// Table classes need a $PAGE for flexible_table.
$PAGE->set_url(new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]));
$PAGE->set_context($activity->context());

probe('table: pickteam_table page of 50 (1500 listed)', function () use ($activity, $cm) {
    $table = new \mod_selfselectadvanced\table\pickteam_table(
        'scaleprobe1',
        $activity,
        new moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $cm->id]),
        '',
        false,
        true
    );
    ob_start();
    $table->out(50, false);
    $html = ob_get_clean();

    return strlen($html);
});

probe('table: groups_table page of 50', function () use ($activity, $gatekeeper, $cm) {
    $table = new \mod_selfselectadvanced\table\groups_table(
        'scaleprobe2',
        $activity,
        $gatekeeper,
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        '',
        false
    );
    ob_start();
    $table->out(50, false);
    $html = ob_get_clean();

    return strlen($html);
});

probe('table: groups_table DOWNLOAD cost (col_size x all rows)', function () use ($activity, $gatekeeper, $cm, $DB) {
    // The download dumps every row through the table's OWN query, so
    // the preloaded seat aggregates ride along (RCA-1). Walk every row
    // through the real column callback, exactly as the export does.
    $table = new \mod_selfselectadvanced\table\groups_table(
        'scaleprobe3',
        $activity,
        $gatekeeper,
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        '',
        true
    );
    $rows = 0;
    $rs = $DB->get_recordset_sql(
        "SELECT {$table->sql->fields} FROM {$table->sql->from} WHERE {$table->sql->where}",
        $table->sql->params
    );
    foreach ($rs as $row) {
        $table->col_size($row);
        $rows++;
    }
    $rs->close();

    return $rows;
});

probe('report: flagged anomalies build_rows', function () use ($activity, $gatekeeper) {
    return count(\mod_selfselectadvanced\table\flagged_anomalies_table::build_rows(
        $activity,
        $gatekeeper->resolver()
    ));
});

probe('report: gridreport build_rows', function () use ($activity) {
    return count(\mod_selfselectadvanced\table\gridreport_table::build_rows($activity, 5, ''));
});

probe('report: evaluator compliance_for_activity (all groups)', function () use ($activity, $groupids) {
    return count(evaluator::compliance_for_activity($activity, $groupids));
});

probe('report: guides with_load (200 guides)', function () use ($activity, $gatekeeper) {
    return count(guides::with_load($activity, $gatekeeper->resolver(), true));
});

probe('service: stage 20 moves + validate_set', function () use ($api, $activity, $DB, &$movesreserve, $groupids) {
    $moveids = [];
    $targets = array_slice($groupids, (int) (count($groupids) / 2), 20);
    foreach ($targets as $tindex => $target) {
        if (!$movesreserve) {
            break;
        }
        $user = (int) array_shift($movesreserve);
        $move = $api->moves()->stage($user, null, (int) $target, false, null, 2);
        $moveids[] = (int) $move->id;
    }
    $api->moves()->validate_set($moveids);

    return count($moveids);
});

probe('service: handover propose + accept', function () use ($api, $activity, $DB, $groupids, $guideids) {
    $gid = (int) $groupids[(int) (count($groupids) / 3)];
    $DB->set_field('selfselectadvanced_group', 'state', 'pending_guide', ['id' => $gid]);
    $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guideids[0], ['id' => $gid]);
    $api->handover()->propose($gid, (int) $guideids[1], (int) $guideids[0]);
    $api->handover()->accept($gid, (int) $guideids[1]);

    return true;
});

// ---------------------------------------------------------------------
// 1.15.0 probes (RCA docs/audits/rca-core-sync-caps-prefix.md): the
// core push, the good-neighbour membership audit, the deleted-account
// roster cleanup, and the manager-controlled id prefix.
probe('service: freeze - push 5 members to core groups', function () use ($DB, $activity, $groupids, $guideids, $now) {
    $gid = (int) $groupids[10];
    $DB->set_field('selfselectadvanced_group', 'state', 'firm', ['id' => $gid]);
    $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guideids[2], ['id' => $gid]);
    $DB->set_field('selfselectadvanced_group', 'timeapproved', $now, ['id' => $gid]);
    $frozen = \mod_selfselectadvanced\local\freeze::freeze_group(
        $activity,
        groups::get($activity, $gid),
        (int) $guideids[2]
    );

    return 'coregroup ' . $frozen->coregroupid;
});

probe('service: freeze audit refusal at lowered cap', function () use ($DB, $activity, $groupids, $guideids, $now) {
    $gid = (int) $groupids[11];
    $DB->set_field('selfselectadvanced_group', 'state', 'firm', ['id' => $gid]);
    $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guideids[3], ['id' => $gid]);
    $DB->set_field('selfselectadvanced_group', 'timeapproved', $now, ['id' => $gid]);
    $origcap = (int) $DB->get_field('selfselectadvanced', 'maxmembership', ['id' => $activity->id()]);
    $DB->set_field('selfselectadvanced', 'maxmembership', 0, ['id' => $activity->id()]);
    $freshactivity = activity::from_instance($activity->id());
    $refused = false;
    try {
        \mod_selfselectadvanced\local\freeze::freeze_group(
            $freshactivity,
            groups::get($freshactivity, $gid),
            (int) $guideids[3]
        );
    } catch (moodle_exception $e) {
        $refused = $e->errorcode === 'refusalmembershipaudit';
    }
    $DB->set_field('selfselectadvanced', 'maxmembership', $origcap, ['id' => $activity->id()]);
    if (!$refused) {
        throw new coding_exception('the membership audit MUST refuse a roster over a cap of zero');
    }

    return 'refused as designed, cap restored to ' . $origcap;
});

probe('observer: delete a frozen member account', function () use ($DB, $activity, $groupids) {
    $gid = (int) $groupids[10];
    $member = $DB->get_record_sql(
        "SELECT u.*
           FROM {user} u
           JOIN {selfselectadvanced_member} m ON m.userid = u.id
          WHERE m.groupid = :gid AND m.isleader = 0 AND m.status = :confirmed",
        ['gid' => $gid, 'confirmed' => groups::STATUS_CONFIRMED],
        IGNORE_MULTIPLE
    );
    delete_user($member);
    if (groups::count_memberships($activity, (int) $member->id) !== 0) {
        throw new coding_exception('a deleted account MUST leave every roster');
    }
    $snapshot = \mod_selfselectadvanced\local\freeze::latest_snapshot($gid);
    foreach (json_decode($snapshot->roster, true) as $entry) {
        if ((int) $entry['userid'] === (int) $member->id) {
            throw new coding_exception('the frozen snapshot MUST NOT carry the ghost');
        }
    }

    return 'roster and snapshot clean';
});

probe('service: uidprefix stamps new groups', function () use ($DB, $activity, &$groupless) {
    $DB->set_field('selfselectadvanced', 'uidprefix', 'VIT', ['id' => $activity->id()]);
    $freshactivity = activity::from_instance($activity->id());
    $group = (new api($freshactivity))->create_group(
        (int) array_shift($groupless),
        'Prefix probe',
        'T',
        '<p>b</p>',
        FORMAT_HTML
    );
    $DB->set_field('selfselectadvanced', 'uidprefix', 'SSA', ['id' => $activity->id()]);
    if (strpos($group->pluginuid, 'VIT-') !== 0) {
        throw new coding_exception('uidprefix was not applied: ' . $group->pluginuid);
    }

    return $group->pluginuid;
});

probe('service: name_taken course-wide at ~1900 groups', function () use ($activity) {
    // 1.16.0: uniqueness widened from the activity to the course. The
    // check must stay a single indexed probe, not a scan.
    if (!groups::name_taken($activity, 'Scale team 0042')) {
        throw new coding_exception('name_taken missed an existing name');
    }
    if (groups::name_taken($activity, 'No such team anywhere')) {
        throw new coding_exception('name_taken invented a clash');
    }

    return 'both verdicts correct';
});

probe('service: project id template renders', function () use ($DB, $activity, &$groupless) {
    $DB->set_field('selfselectadvanced', 'uidformat', '{prefix}/{number}', ['id' => $activity->id()]);
    $freshactivity = activity::from_instance($activity->id());
    if (!$groupless) {
        throw new coding_exception('the groupless pool ran dry before the id-template probe');
    }
    $group = (new api($freshactivity))->create_group(
        (int) array_shift($groupless),
        'Template probe',
        'T',
        '<p>b</p>',
        FORMAT_HTML
    );
    $DB->set_field('selfselectadvanced', 'uidformat', null, ['id' => $activity->id()]);
    if (strpos($group->pluginuid, '/') === false) {
        throw new coding_exception('id template was not applied: ' . $group->pluginuid);
    }

    return $group->pluginuid;
});

probe('service: tickets - file 50 + queue + exclusivity', function () use ($DB, $activity, $groupids, $guideids, $now) {
    // Fifty firm guided teams file composition-change requests; the
    // queue lists them FIFO; the claim stays exclusive under load.
    $tickets = [];
    for ($i = 0; $i < 50; $i++) {
        $gid = (int) $groupids[200 + $i];
        $guideid = (int) $guideids[$i % count($guideids)];
        $DB->set_field('selfselectadvanced_group', 'state', 'firm', ['id' => $gid]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guideid, ['id' => $gid]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', $now, ['id' => $gid]);
        $tickets[] = \mod_selfselectadvanced\local\tickets::file(
            $activity,
            groups::get($activity, $gid),
            \mod_selfselectadvanced\local\tickets::TYPE_COMPCHANGE,
            'Scale probe request ' . $i,
            FORMAT_PLAIN,
            $guideid
        );
    }
    $queue = \mod_selfselectadvanced\local\tickets::queue($activity);
    if (count($queue) < 50) {
        throw new coding_exception('queue lost tickets: ' . count($queue));
    }
    // Two DIFFERENT workers take the same ticket: exactly one wins and
    // the loser is told who holds it.
    $first = (int) $tickets[0]->id;
    $winner = (int) get_admin()->id;
    $loser = (int) $guideids[count($guideids) - 1];
    \mod_selfselectadvanced\local\tickets::claim($activity, $first, $winner);
    try {
        \mod_selfselectadvanced\local\tickets::claim($activity, $first, $loser);
        throw new coding_exception('a second worker claimed a ticket already held');
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'refusalticketclaimed') {
            throw $e;
        }
    }
    $held = $DB->get_field('selfselectadvanced_ticket', 'claimedby', ['id' => $first]);
    if ((int) $held !== $winner) {
        throw new coding_exception('the claim was not exclusive: held by ' . $held);
    }

    return count($queue) . ' queued, claim exclusive';
});

probe('service: guides selectable, students-approach (200 guides)', function () use ($DB, $activity, $gatekeeper) {
    // The property under test is that students-approach mode filters
    // NOTHING by remaining capacity - omitting a full guide would
    // itself advertise their load. Compared against the same call with
    // the switch off, so guides hidden for other reasons (a guide-scope
    // override) do not confuse the verdict.
    $DB->set_field('selfselectadvanced', 'studentapproach', 0, ['id' => $activity->id()]);
    $off = count(guides::selectable(activity::from_instance($activity->id()), $gatekeeper->resolver()));
    $DB->set_field('selfselectadvanced', 'studentapproach', 1, ['id' => $activity->id()]);
    $on = count(guides::selectable(activity::from_instance($activity->id()), $gatekeeper->resolver()));
    $DB->set_field('selfselectadvanced', 'studentapproach', 0, ['id' => $activity->id()]);
    if ($on < $off) {
        throw new coding_exception("students-approach hid guides: on={$on} off={$off}");
    }

    return "listed on={$on} off={$off} (no capacity filtering when students approach)";
});

probe('table: assignqueue page of 50 (awaiting a guide)', function () use ($activity, $cm) {
    // 1.17.0 C1: the tab must cost the same at 1900 teams as at 19.
    $table = new \mod_selfselectadvanced\table\assignqueue_table(
        'probeassign',
        $activity,
        \mod_selfselectadvanced\table\assignqueue_table::MODE_UNASSIGNED,
        true,
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id])
    );
    ob_start();
    $table->out(50, true);
    $html = ob_get_clean();

    return strlen($html) . ' bytes';
});

probe('table: assignqueue page of 50 (changing a guide)', function () use ($activity, $cm) {
    $table = new \mod_selfselectadvanced\table\assignqueue_table(
        'probereassign',
        $activity,
        \mod_selfselectadvanced\table\assignqueue_table::MODE_REASSIGN,
        true,
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id])
    );
    ob_start();
    $table->out(50, true);
    $html = ob_get_clean();

    return strlen($html) . ' bytes';
});

probe('service: guide search for a picker keystroke (200 guides)', function () use ($activity, $gatekeeper) {
    // 1.18 B: the measurement the searchable pickers exist for. Every
    // keystroke in every picker costs this, so it has to stay flat -
    // the name filter runs BEFORE the per-guide override work, which
    // is what stops it scaling with the size of the school.
    $hits = \mod_selfselectadvanced\local\guides::search($activity, $gatekeeper->resolver(), 'Scale', 50);
    if (count($hits) < 1) {
        throw new coding_exception('the guide search found nobody at all');
    }
    if (count($hits) > 50) {
        throw new coding_exception('the guide search ignored its cap: ' . count($hits));
    }

    return count($hits) . ' hits, capped at 50';
});

probe('service: guide search miss (no match at 200 guides)', function () use ($activity, $gatekeeper) {
    $hits = \mod_selfselectadvanced\local\guides::search($activity, $gatekeeper->resolver(), 'Zzyzx Nobody', 50);
    if ($hits !== []) {
        throw new coding_exception('a query nobody matches returned ' . count($hits) . ' rows');
    }

    return '0 hits';
});

probe('table: guide loads page of 50, filtered (200 guides)', function () use ($activity, $gatekeeper, $cm) {
    // 1.18 F: filters and download joined the paging it got in 1.17.
    $rows = [];
    foreach (
        \mod_selfselectadvanced\local\guides::with_load($activity, $gatekeeper->resolver(), true, 'Scale') as $guide
    ) {
        $rows[] = (object) [
            'fullname' => $guide->fullname,
            'used' => $guide->used,
            'max' => $guide->max,
            'remaining' => $guide->remaining,
        ];
    }
    $table = new \mod_selfselectadvanced\table\guideloads_table(
        'probeloads',
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id, 'assigntab' => 'loads'])
    );
    ob_start();
    $table->display_rows($rows, 50);
    $html = ob_get_clean();

    return count($rows) . ' guides, ' . strlen($html) . ' bytes';
});

probe('service: team search for a move-form keystroke (~1900 teams)', function () use ($activity) {
    // 1.18.2 B: the move form carried TWO selects over every team, and
    // the override form an autocomplete filtered in the browser. Both
    // search now, and this is the cost of one keystroke in either.
    $hits = \mod_selfselectadvanced\local\groups::search($activity, 'Scale', 50);
    if (count($hits) < 1) {
        throw new coding_exception('the team search found nothing at all');
    }
    if (count($hits) > 50) {
        throw new coding_exception('the team search ignored its cap: ' . count($hits));
    }

    return count($hits) . ' hits, capped at 50';
});

probe('service: guidecap - file 20 + queue + grant', function () use ($DB, $activity, $guideids) {
    // 1.18 C: a ticket type with no team, through the whole queue.
    $manager = $DB->get_field_sql(
        "SELECT u.id FROM {user} u JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid WHERE r.shortname = ? AND ra.contextid = ?",
        ['editingteacher', \context_course::instance($activity->courseid())->id]
    );
    if (!$manager) {
        throw new coding_exception('the harness has no editing teacher to work the queue');
    }
    $filed = [];
    for ($i = 0; $i < 20; $i++) {
        $filed[] = \mod_selfselectadvanced\local\tickets::file_guidecap(
            $activity,
            50 + $i,
            'Scale probe capacity request ' . $i,
            FORMAT_PLAIN,
            (int) $guideids[$i]
        );
    }
    $queue = \mod_selfselectadvanced\local\tickets::queue($activity, (int) $manager);
    $capsinqueue = 0;
    foreach ($queue as $ticket) {
        if ($ticket->type === \mod_selfselectadvanced\local\tickets::TYPE_GUIDECAP) {
            $capsinqueue++;
        }
    }
    if ($capsinqueue !== 20) {
        throw new coding_exception('expected 20 capacity requests in the queue, saw ' . $capsinqueue);
    }

    // One of them all the way through: claim, grant, override written.
    $first = $filed[0];
    \mod_selfselectadvanced\local\tickets::claim($activity, (int) $first->id, (int) $manager);
    \mod_selfselectadvanced\local\tickets::grant_guidecap(
        $activity,
        (int) $first->id,
        'Granted by the scale probe',
        FORMAT_PLAIN,
        (int) $manager
    );
    $ceiling = (new api($activity))->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guideids[0]);
    if ($ceiling->value !== 50) {
        throw new coding_exception('grant did not write the override: ceiling is ' . $ceiling->value);
    }

    return '20 filed, 1 granted';
});

probe('service: contacts - 50 approaches + remaining', function () use ($DB, $activity, $groupids, $guideids) {
    $DB->set_field('selfselectadvanced', 'contactmax', 3, ['id' => $activity->id()]);
    $fresh = activity::from_instance($activity->id());
    $sent = 0;
    for ($i = 0; $i < 50; $i++) {
        $gid = (int) $groupids[400 + $i];
        $DB->set_field('selfselectadvanced_group', 'state', 'forming', ['id' => $gid]);
        $DB->set_field('selfselectadvanced_group', 'guideid', null, ['id' => $gid]);
        $group = groups::get($fresh, $gid);
        \mod_selfselectadvanced\local\contacts::send(
            $fresh,
            $group,
            (int) $guideids[$i % count($guideids)],
            'Scale probe approach ' . $i,
            FORMAT_PLAIN,
            (int) $group->leaderid
        );
        $sent++;
    }
    $left = \mod_selfselectadvanced\local\contacts::remaining($fresh, (int) $groupids[400]);
    if ($left !== 2) {
        throw new coding_exception('remaining after one approach should be 2, got ' . $left);
    }

    return $sent . ' approaches, remaining correct';
});

cli_writeln('');
cli_writeln('=== SUMMARY (worst first) ===');
usort($probes, static fn($a, $b) => $b[1] <=> $a[1]);
foreach ($probes as [$label, $t, $reads, $writes]) {
    cli_writeln(sprintf('%8.2fs reads=%-6d writes=%-5d %s', $t, $reads, $writes, $label));
}
