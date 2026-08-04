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
 * Maintainer utility: seed the guided demonstration course.
 *
 * Not part of the released plugin - docs/ is excluded from the release
 * zip. Builds one course with the activity configured with a 2-level
 * department vocabulary, a counting rule, a seat plan, the pick-that-team
 * (EOI) flow enabled, and groups left in every interesting lifecycle
 * state, so the how-to deck can show each step with a real screenshot:
 *
 *   php mod/selfselectadvanced/docs/tools/seed_howto.php --reset
 *
 * It is idempotent: --reset deletes the course and rebuilds it, so the
 * same run always produces the same screenshots. The department
 * vocabulary is site-wide and is left in place across runs (ensure()
 * is itself idempotent); demo users and their attributes are reused
 * and updated in place rather than recreated.
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
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\attributes\depts;
use mod_selfselectadvanced\local\attributes\manager as attrmanager;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\quota\store as quotastore;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\volunteering;

[$options, $unrecognised] = cli_get_params(
    ['shortname' => 'SSAHOWTO', 'password' => 'SsaDemo#2026', 'reset' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($options['help']) {
    cli_writeln('Seed the guided Group self-selection (Advanced) demonstration. '
        . 'Options: --shortname= --password= --reset');
    exit(0);
}

\core\session\manager::set_user(get_admin());
$shortname = (string) $options['shortname'];
$password = (string) $options['password'];

// Seeding drives the real transition services, so every transition
// sends its real notification. The demonstration box has no interest
// in that mail; the messages still land in Moodle regardless.
$CFG->noemailever = true;

/**
 * Create or fetch one demonstration user and enrol them in the course
 * with the given role.
 *
 * @param string $username the username
 * @param string $first first name
 * @param string $last last name
 * @param string $rolename student, teacher (guide) or editingteacher
 * @param stdClass $course the course
 * @param string $password the shared password
 * @return stdClass the user record
 */
function selfselectadvanced_howto_user(
    string $username,
    string $first,
    string $last,
    string $rolename,
    stdClass $course,
    string $password
): stdClass {
    global $DB, $CFG;

    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $userid = user_create_user((object) [
            'username' => $username,
            'password' => $password,
            'firstname' => $first,
            'lastname' => $last,
            'email' => $username . '@example.com',
            'mnethostid' => $CFG->mnet_localhost_id,
            'confirmed' => 1,
            'auth' => 'manual',
        ], true, false);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    } else {
        // Existing accounts carry over between resets: keep the
        // password in step with what the capture CLI will use.
        user_update_user((object) ['id' => $user->id, 'password' => $password], true, false);
    }
    if ($rolename !== '') {
        $role = $DB->get_record('role', ['shortname' => $rolename], '*', MUST_EXIST);
        enrol_try_internal_enrol($course->id, $user->id, $role->id);
    }

    return $user;
}

/**
 * Grant a user real site administrator status, if not already held.
 *
 * The site-wide plugin settings page (export format default) is
 * guarded by moodle/site:config, and the participant-attributes and
 * departments pages are guarded by a system-context capability with
 * no default archetype grant at all - both are, in practice, site
 * administrator surfaces, so the how-to deck's administrator persona
 * needs to genuinely be one.
 *
 * @param stdClass $user the user to promote
 */
function selfselectadvanced_howto_make_admin(stdClass $user): void {
    global $CFG;

    $ids = array_filter(array_map('trim', explode(',', $CFG->siteadmins)));
    if (!in_array((string) $user->id, $ids, true)) {
        $ids[] = (string) $user->id;
        set_config('siteadmins', implode(',', $ids));
    }
}

/**
 * Build a small, fully spec-compliant one-page PDF (correct xref
 * table, not a truncated fake), so the proposal-PDF screenshots have
 * something a real PDF viewer renders instead of a broken embed.
 *
 * @param string $text the single line of body text
 * @return string raw PDF bytes
 */
function selfselectadvanced_howto_minimal_pdf(string $text): string {
    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
        . "/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $stream = "BT /F1 18 Tf 72 700 Td (" . addcslashes($text, '()\\') . ") Tj ET";
    $objects[5] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "$num 0 obj\n$body\nendobj\n";
    }
    $xrefstart = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefstart\n%%EOF";

    return $pdf;
}

/**
 * Attach the demonstration proposal PDF to a group, replacing any
 * existing one (idempotent across --reset rebuilds).
 *
 * @param activity $activity the activity
 * @param stdClass $group the group row
 * @param string $label text baked into the PDF body, for variety
 */
function selfselectadvanced_howto_attach_proposal(activity $activity, stdClass $group, string $label): void {
    $fs = get_file_storage();
    $contextid = $activity->context()->id;
    $fs->delete_area_files($contextid, 'mod_selfselectadvanced', 'proposal', (int) $group->id);
    $fs->create_file_from_string([
        'contextid' => $contextid,
        'component' => 'mod_selfselectadvanced',
        'filearea' => 'proposal',
        'itemid' => $group->id,
        'filepath' => '/',
        'filename' => 'proposal.pdf',
        'mimetype' => 'application/pdf',
    ], selfselectadvanced_howto_minimal_pdf($label));
}

$existingcourse = $DB->get_record('course', ['shortname' => $shortname]);
if ($existingcourse && $options['reset']) {
    delete_course($existingcourse, false);
    cli_writeln("Deleted the previous {$shortname} course");
    $existingcourse = false;
}
if ($existingcourse) {
    cli_error("Course {$shortname} already exists. Re-run with --reset to rebuild it.");
}

$course = create_course((object) [
    'fullname' => 'Group self-selection (Advanced) - guided demonstration',
    'shortname' => $shortname,
    'category' => 1,
    'format' => 'topics',
    'numsections' => 3,
]);
cli_writeln("Created course {$course->shortname} (id {$course->id})");

// Personas. howto.admin is promoted to a real site administrator
// below; the rest are ordinary course participants.
$admin = selfselectadvanced_howto_user('howto.admin', 'Ada', 'Okafor', '', $course, $password);
selfselectadvanced_howto_make_admin($admin);
$teacher = selfselectadvanced_howto_user('howto.teacher', 'Priya', 'Menon', 'editingteacher', $course, $password);
$guide = selfselectadvanced_howto_user('howto.guide', 'Sam', 'Okoye', 'teacher', $course, $password);
$guide2 = selfselectadvanced_howto_user('howto.guide2', 'Lena', 'Fischer', 'teacher', $course, $password);
$s01 = selfselectadvanced_howto_user('howto.s01', 'Aiden', 'Cole', 'student', $course, $password);
$s02 = selfselectadvanced_howto_user('howto.s02', 'Priti', 'Nandan', 'student', $course, $password);
$s03 = selfselectadvanced_howto_user('howto.s03', 'Rohan', 'Desai', 'student', $course, $password);
$s04 = selfselectadvanced_howto_user('howto.s04', 'Meera', 'Iyer', 'student', $course, $password);
$s05 = selfselectadvanced_howto_user('howto.s05', 'Farah', 'Khan', 'student', $course, $password);
$s06 = selfselectadvanced_howto_user('howto.s06', 'Tomasz', 'Nowak', 'student', $course, $password);
$s07 = selfselectadvanced_howto_user('howto.s07', 'Grace', 'Owusu', 'student', $course, $password);
$s08 = selfselectadvanced_howto_user('howto.s08', 'Ben', 'Ortiz', 'student', $course, $password);
cli_writeln('Created personas: howto.admin, howto.teacher, howto.guide, howto.guide2, howto.s01..s08');

// Site-wide, 2-level department vocabulary (spec 1.1.0): the CSV
// importer and the quota/slot value pickers only offer values from
// this tree once it is non-empty. depts::ensure() is idempotent, and
// the acting administrator is stated explicitly (AUTH-001).
depts::ensure('Computer Science', 'AI', (int) $admin->id);
depts::ensure('Computer Science', 'Systems', (int) $admin->id);
depts::ensure('Data Science', 'Analytics', (int) $admin->id);
depts::ensure('Data Science', 'Engineering', (int) $admin->id);
cli_writeln('Department vocabulary: Computer Science (AI, Systems), Data Science (Analytics, Engineering)');

// Participant attributes: gender, department, sub-department, mobile,
// programme. Assignments are deliberate: Comet's sole confirmed member
// (s02) is NOT yet compliant, so its composition panel has something
// real to say while it is still forming; the leaders who go on to
// submit (s04, s05, s06+s07) are each quota-and-seat-plan compliant on
// their own confirmed roster, because submission, approval and
// freezing all gate on live compliance (spec 8.2).
$attrs = [
    (int) $s01->id => ['gender' => 'Female', 'department' => 'Data Science', 'subdepartment' => 'Engineering',
        'program' => 'BTech'],
    (int) $s02->id => ['gender' => 'Male', 'department' => 'Data Science', 'subdepartment' => 'Analytics',
        'program' => 'BTech'],
    (int) $s03->id => ['gender' => 'Male', 'department' => 'Computer Science', 'subdepartment' => 'Systems',
        'mobile' => '919800000003', 'program' => 'BTech'],
    (int) $s04->id => ['gender' => 'Female', 'department' => 'Computer Science', 'subdepartment' => 'Engineering',
        'mobile' => '919800000004', 'program' => 'BTech'],
    (int) $s05->id => ['gender' => 'Female', 'department' => 'Computer Science', 'subdepartment' => 'AI',
        'program' => 'MSc'],
    (int) $s06->id => ['gender' => 'Male', 'department' => 'Computer Science', 'subdepartment' => 'Systems',
        'program' => 'MSc'],
    (int) $s07->id => ['gender' => 'Female', 'department' => 'Computer Science', 'subdepartment' => 'Systems',
        'program' => 'MSc'],
    (int) $s08->id => ['gender' => 'Male', 'department' => 'Data Science', 'subdepartment' => 'Analytics',
        'program' => 'MSc'],
];
foreach ($attrs as $userid => $values) {
    attrmanager::set($userid, $values, (int) $admin->id);
}
cli_writeln('Ingested participant attributes for all eight students');

// The activity itself. Team size is deliberately small (minsize 1) so
// the demonstration's eight students can populate five groups across
// every lifecycle state without inventing a larger cohort; a solo
// leader is still a legal team.
//
// GUIDE MODE - studentapproach MUST be 0 here, and is passed
// explicitly. The deck this course exists to photograph shows guides
// volunteering a capacity, guides expressing interest in listed teams,
// a leader accepting one of those interests, and a guide reviewing a
// submission. Students-approach mode (strategy 1.16 A) refuses the
// first two outright - volunteering::set() and eoi::express() both
// throw 'refusalstudentapproach' - so it is not a mode this course can
// be in. Dropping the volunteering and interest calls instead would
// delete the frames the deck is FOR, which is why the setting moves
// rather than the calls.
//
// It has to be passed rather than left out: the column's DB default is
// 1 (db/install.xml), and add_moduleinfo() bypasses the mod form, so
// omitting it silently built an activity in a combination the form's
// own validator rejects - studentapproach=1 with eoienabled=1 and
// guidevolunteer=1 are errstudentapproacheoi and
// errstudentapproachvolunteer in settings_validator. The script then
// died at the first volunteering::set() and the five named groups were
// never created, which is what blocked capture_howto.php.
//
// cmidnumber: supplied for the same reason a real form submission
// supplies it. add_moduleinfo() -> edit_module_post_actions() reads
// $moduleinfo->cmidnumber unconditionally when the module has a grade
// item (grade => 100 below gives it one), so leaving it out emitted
// "Undefined property: stdClass::$cmidnumber" from course/modlib.php
// on every run. Empty string is what the form's ID number field sends
// when a person leaves it blank.
$module = $DB->get_record('modules', ['name' => 'selfselectadvanced'], '*', MUST_EXIST);
$moduleinfo = add_moduleinfo((object) [
    'modulename' => 'selfselectadvanced',
    'module' => $module->id,
    'course' => $course->id,
    'section' => 0,
    'visible' => 1,
    'name' => 'Studio project teams',
    'intro' => '<p>Form a team of up to four for the term studio project. Every team needs at least '
        . 'one member from Computer Science and needs at least one Female member; list your team once '
        . "it is forming and a guide may pick it before you submit.</p>",
    'introformat' => FORMAT_HTML,
    'cmidnumber' => '',
    'grade' => 100,
    'studentapproach' => 0,
    'minsize' => 1,
    'maxsize' => 4,
    'maxlead' => 1,
    'maxmembership' => 1,
    'guidemode' => 0,
    'maxguided' => 10,
    'guidewindow' => 3 * DAYSECS,
    'guideautoapprove' => 0,
    'guidevolunteer' => 1,
    'eoienabled' => 1,
    'eoiwindow' => 3 * DAYSECS,
    'eoimax' => 3,
    'eoisequential' => 0,
    'eoipeers' => 1,
    'timeopen' => 0,
    'timedue' => 0,
    'timecutoff' => 0,
    'penaltytype' => 0,
    'penaltyperday' => 0,
    'inviteexpiry' => 0,
    'autogroup' => 0,
    'proposalrequired' => 1,
    'minmembership' => 0,
    'defaulterpenalty' => 0,
    'incompletepenalty' => 0,
    'leadershare' => 60,
], $course);
$instanceid = (int) $moduleinfo->instance;
$cm = get_coursemodule_from_instance('selfselectadvanced', $instanceid, $course->id, false, MUST_EXIST);
cli_writeln("Created activity (cmid {$cm->id})");

$activity = activity::from_instance($instanceid);
$api = new api($activity);

// The counting rule (spec 8.2): at least one Female member per team.
//
// The actor is passed explicitly, exactly as slots::create() below
// needs it. quota\store::save() gained a REQUIRED third argument in
// 1.20.1 (audit D7-b) so that the manage capability is asked at the
// service instead of only at quotas.php, and this call site was the one
// caller outside the plugin's own pages. Omitting it is not a warning:
// it is "Too few arguments to function ... exactly 3 expected", thrown
// from store.php:100, which kills the whole seed run right after the
// activity is created and before a single team exists. Measured on the
// dev site 2026-08-03; php -l cannot see it, and a source-text pin over
// this file cannot see it either, because the file is syntactically
// perfect either way.
quotastore::save($activity, (object) [
    'dimension' => 'gender',
    'rtype' => 'value',
    'value' => 'Female',
    'mincount' => 1,
    'maxcount' => null,
], (int) $admin->id);
// The seat plan (spec 4.7): at least one member from Computer Science.
// A single-slot plan is deliberate: the booking algorithm assigns each
// member to at most ONE slot (spec: greedy, in slot order), so a
// second slot would need a second, distinct member in every team that
// has to reach it - and this demonstration's smallest teams are solo
// leaders.
slots::create($activity, (object) [
    'mincount' => 1,
    'dimension' => 'department',
    'matchtype' => 'value',
    'value' => 'Computer Science',
    'allowoverlap' => 0,
], (int) $admin->id);
cli_writeln('Counting rule (>=1 Female) and seat plan (>=1 Computer Science) saved');

// Guides declare their volunteered capacity (1.7.0): consulted ahead
// of the activity's own maxguided ceiling.
volunteering::set($activity, (int) $guide->id, 5);
volunteering::set($activity, (int) $guide2->id, 4);
cli_writeln('Guide capacity: Sam Okoye volunteered for 5, Lena Fischer for 4');

// Comet: forming and listed for guides, with an unanswered invitation
// and two open expressions of interest - the "create a group",
// "invite a teammate", "composition panel", "list the team" and
// "interest from guides" steps all come from this one page. Its sole
// confirmed member is deliberately NOT compliant yet, so the
// composition panel has a real shortfall to word.
$comet = $api->create_group(
    (int) $s02->id,
    'Comet',
    'Campus micro-forecasting',
    '<p>Hyper-local weather forecasting for the three campus sports grounds, built from our own sensors.</p>',
    FORMAT_HTML
);
$api->invitations()->send($comet, (int) $s01->id, (int) $s02->id);
$DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $comet->id]);
$DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $comet->id]);
eoi::express($activity, (int) $comet->id, (int) $guide->id, '<p>I ran a very similar project last year.</p>');
eoi::express($activity, (int) $comet->id, (int) $guide2->id, '<p>Happy to help with the sensor calibration.</p>');
cli_writeln("Comet: forming, listed, invitation pending to Aiden Cole, two open guide interests "
    . "(group {$comet->id})");

// Orbit: forming and listed, with one expression of interest the
// leader has already accepted - the guide is pre-assigned but the
// team has not submitted yet ("accept an interest").
$orbit = $api->create_group(
    (int) $s03->id,
    'Orbit',
    'Satellite pass predictor',
    '<p>A pointer app for the campus telescope that tracks the next visible satellite pass.</p>',
    FORMAT_HTML
);
$DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $orbit->id]);
$DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $orbit->id]);
$orbiteoi = eoi::express($activity, (int) $orbit->id, (int) $guide2->id, '<p>This is exactly my area.</p>');
eoi::respond($activity, $orbiteoi, true, (int) $s03->id);
cli_writeln("Orbit: forming, listed, guide Lena Fischer pre-assigned via an accepted interest (group {$orbit->id})");

// Vertex: submitted for guide review with its written proposal
// attached - the "submit for guide review" step, and the guide's own
// review queue.
$vertex = $api->create_group(
    (int) $s04->id,
    'Vertex',
    'Structural load sensors',
    '<p>Strain gauges on the footbridge, logged continuously and shown on a public dashboard.</p>',
    FORMAT_HTML
);
selfselectadvanced_howto_attach_proposal($activity, $vertex, 'Vertex - structural load sensors proposal');
$api->lifecycle()->submit($vertex, (int) $guide->id, (int) $s04->id);
cli_writeln("Vertex: submitted to guide Sam Okoye, awaiting review, proposal attached (group {$vertex->id})");

// Solstice: firm, with a guide-awarded mark - the "firm with a mark"
// step. A single, quota-and-seat-plan-compliant leader is a legal team
// at minsize 1.
$solstice = $api->create_group(
    (int) $s05->id,
    'Solstice',
    'Solar panel cleaning robot',
    '<p>A rail-mounted robot that keeps the rooftop solar array free of dust between the rains.</p>',
    FORMAT_HTML
);
selfselectadvanced_howto_attach_proposal($activity, $solstice, 'Solstice - solar panel cleaning robot proposal');
$api->lifecycle()->submit($solstice, (int) $guide->id, (int) $s05->id);
$solstice = groups::get($activity, (int) $solstice->id);
$api->lifecycle()->approve($solstice, (int) $guide->id);
ledger::set_award($activity, $solstice, 88.0, (int) $guide->id);
cli_writeln("Solstice: firm, approved by Sam Okoye, awarded mark 88 (group {$solstice->id})");

// Meridian: frozen, mirrored into a core course group - the "frozen"
// step. Two confirmed members so the roster shows how a second
// person's attributes contribute alongside the leader's own.
$meridian = $api->create_group(
    (int) $s06->id,
    'Meridian',
    'Library footfall counter',
    '<p>Door-mounted counters and a live occupancy display for the main library reading room.</p>',
    FORMAT_HTML
);
$api->invitations()->send($meridian, (int) $s07->id, (int) $s06->id);
$api->invitations()->accept($meridian, (int) $s07->id);
selfselectadvanced_howto_attach_proposal($activity, $meridian, 'Meridian - library footfall counter proposal');
$api->lifecycle()->submit($meridian, (int) $guide2->id, (int) $s06->id);
$meridian = groups::get($activity, (int) $meridian->id);
$api->lifecycle()->approve($meridian, (int) $guide2->id);
freeze::freeze_group($activity, $meridian, (int) $guide2->id);
cli_writeln("Meridian: frozen, core group synced, guide Lena Fischer (group {$meridian->id})");

// Every transition above ran inside one PHP request, so without this
// pass every timestamp is within a second of another and the
// formation-analytics medians all read as "now" - honest, but not a
// convincing demonstration of what the report is for. Backdating is
// display-only: nothing here is re-read by any gate.
$now = time();
$DB->set_field('selfselectadvanced_group', 'timecreated', $now - (5 * DAYSECS), ['id' => $comet->id]);
$DB->set_field('selfselectadvanced_group', 'timelisted', $now - (4 * DAYSECS), ['id' => $comet->id]);
$DB->set_field('selfselectadvanced_group', 'timecreated', $now - (5 * DAYSECS), ['id' => $orbit->id]);
$DB->set_field('selfselectadvanced_group', 'timelisted', $now - (4 * DAYSECS), ['id' => $orbit->id]);
$DB->set_field('selfselectadvanced_eoi', 'timecreated', $now - (3 * DAYSECS), ['groupid' => $comet->id]);
$DB->set_field('selfselectadvanced_eoi', 'timecreated', $now - (3 * DAYSECS), ['groupid' => $orbit->id]);
$DB->set_field('selfselectadvanced_eoi', 'timeresponded', $now - (2 * DAYSECS), ['groupid' => $orbit->id]);
$DB->set_field('selfselectadvanced_group', 'timecreated', $now - (6 * DAYSECS), ['id' => $vertex->id]);
$DB->set_field('selfselectadvanced_group', 'timesubmitted', $now - (1 * DAYSECS), ['id' => $vertex->id]);
$DB->set_field('selfselectadvanced_group', 'timecreated', $now - (6 * DAYSECS), ['id' => $solstice->id]);
$DB->set_field('selfselectadvanced_group', 'timesubmitted', $now - (3 * DAYSECS), ['id' => $solstice->id]);
$DB->set_field('selfselectadvanced_group', 'timeapproved', $now - (1 * DAYSECS), ['id' => $solstice->id]);
$DB->set_field('selfselectadvanced_group', 'timecreated', $now - (7 * DAYSECS), ['id' => $meridian->id]);
$DB->set_field('selfselectadvanced_group', 'timesubmitted', $now - (4 * DAYSECS), ['id' => $meridian->id]);
$DB->set_field('selfselectadvanced_group', 'timeapproved', $now - (2 * DAYSECS), ['id' => $meridian->id]);
cli_writeln('Backdated timestamps so formation analytics has real medians to report');

$wwwroot = $CFG->wwwroot;
$base = "{$wwwroot}/mod/selfselectadvanced";
cli_writeln('');
cli_writeln('Course:   ' . $wwwroot . '/course/view.php?id=' . $course->id);
cli_writeln('Activity: cmid ' . $cm->id);
cli_writeln('');
cli_writeln('Pages for the how-to deck:');
cli_writeln("  activity settings   {$wwwroot}/course/modedit.php?update={$cm->id}");
cli_writeln("  departments (admin) {$wwwroot}/mod/selfselectadvanced/departments.php");
cli_writeln("  attributes (admin)  {$wwwroot}/mod/selfselectadvanced/attributes.php");
cli_writeln("  counting rule/seats {$base}/quotas.php?id={$cm->id}");
cli_writeln("  Comet (create/invite/list/interest) {$base}/group.php?id={$cm->id}&g={$comet->id}   (as howto.s02)");
cli_writeln("  Orbit (accepted)    {$base}/group.php?id={$cm->id}&g={$orbit->id}   (as howto.s03)");
cli_writeln("  Vertex (submitted)  {$base}/group.php?id={$cm->id}&g={$vertex->id}   (as howto.s04)");
cli_writeln("  Solstice (firm)     {$base}/group.php?id={$cm->id}&g={$solstice->id}   (as howto.s05)");
cli_writeln("  Meridian (frozen)   {$base}/group.php?id={$cm->id}&g={$meridian->id}   (as howto.s06)");
cli_writeln("  pick that team      {$base}/pickteam.php?id={$cm->id}   (as howto.guide or howto.guide2)");
cli_writeln("  guide dashboard     {$base}/guide.php?id={$cm->id}   (as howto.guide or howto.guide2)");
cli_writeln("  interest list       {$base}/eoilist.php?id={$cm->id}   (as howto.guide2)");
cli_writeln("  review Vertex       {$base}/review.php?id={$cm->id}&g={$vertex->id}   (as howto.guide)");
cli_writeln("  reports             {$base}/flagged.php?id={$cm->id}   (as howto.teacher)");
cli_writeln("  analytics           {$base}/analytics.php?id={$cm->id}   (as howto.teacher)");
cli_writeln('');
cli_writeln('Logins: howto.admin (site admin), howto.teacher (editing teacher), '
    . 'howto.guide, howto.guide2 (guides), howto.s01..howto.s08 (students)');
cli_writeln("Password: {$password}");
cli_writeln('');
cli_writeln('Group ids: comet=' . $comet->id . ' orbit=' . $orbit->id
    . ' vertex=' . $vertex->id . ' solstice=' . $solstice->id . ' meridian=' . $meridian->id);
