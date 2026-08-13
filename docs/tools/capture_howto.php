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
 * Maintainer utility: capture the how-to walkthrough screenshots.
 *
 * Maintainer tooling, NOT part of any supported workflow - but it IS in the
 * release zip. This header used to say "docs/ is excluded from the release
 * zip"; the 1.20.37 package contains docs/ in full, and no exclusion manifest
 * exists (external audit FCA-003, 2026-08-13). Either the packaging or this
 * sentence had to change, and the sentence was the false one. Signs each demonstration persona in through the ordinary login
 * form and drives the local Selenium Grid, so every frame is what that
 * role really sees. Group and activity ids are resolved from the
 * database by course shortname and group name, so nothing numeric has
 * to be typed by hand:
 *
 *   php mod/selfselectadvanced/docs/tools/capture_howto.php \
 *       --wwwroot=https://m5pg.ci.ehub.pw --out=/srv/ci/selfselectadvanced-howto
 *
 * Nothing leaves the machine: the browser talks to the local Moodle
 * and the images land in --out. Support --only=NN-topic,NN-topic to
 * redo one or two frames cheaply.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'wwwroot' => '', 'shortname' => 'SSAHOWTO', 'pass' => '', 'out' => '',
        'driver' => 'http://127.0.0.1:4444', 'only' => '', 'help' => false,
    ],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($options['help'] || $options['wwwroot'] === '') {
    cli_writeln('Usage: php capture_howto.php --wwwroot=URL [--shortname=SSAHOWTO] [--out=DIR] '
        . '[--only=03-create-group,04-invite]');
    exit(0);
}
$wwwroot = rtrim((string) $options['wwwroot'], '/');
$shortname = (string) $options['shortname'];
$pass = (string) $options['pass'];
// REFUSE rather than proceed with nothing. This option used to carry a
// hard-coded demonstration password, which shipped in the release zip; the
// default is gone, and an empty value must stop the run rather than silently
// attempt a blank login (external audit FCA-003, 2026-08-13).
if ($pass === '') {
    cli_error('--pass is required: the demonstration password is no longer hard-coded here.');
}
$out = (string) ($options['out'] ?: sys_get_temp_dir() . '/selfselectadvanced-howto');
$driver = rtrim((string) $options['driver'], '/');
@mkdir($out, 0777, true);

// Resolve the course, activity and every named demonstration group
// from the database, so a re-seed never leaves stale ids behind here.
$course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
$instances = get_fast_modinfo($course)->get_instances_of('selfselectadvanced');
$cm = reset($instances);
if (!$cm) {
    cli_error("No selfselectadvanced activity found in course {$shortname}. Run seed_howto.php first.");
}
$cmid = (int) $cm->id;
$activityid = (int) $cm->instance;

$groupnames = ['Comet', 'Orbit', 'Vertex', 'Solstice', 'Meridian'];
$groups = [];
foreach ($groupnames as $name) {
    $row = $DB->get_record('selfselectadvanced_group', ['activityid' => $activityid, 'name' => $name]);
    if (!$row) {
        cli_error("Group '$name' not found in the {$shortname} activity. Run seed_howto.php first.");
    }
    $groups[strtolower($name)] = (int) $row->id;
}

/**
 * One WebDriver call.
 *
 * @param string $method HTTP method
 * @param string $url absolute endpoint
 * @param array|null $payload JSON body
 * @return array decoded response
 */
function selfselectadvanced_wd(string $method, string $url, ?array $payload = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        cli_error('WebDriver call failed: ' . curl_error($ch));
    }
    curl_close($ch);

    return json_decode((string) $body, true) ?: [];
}

/**
 * Start a headless browser session.
 *
 * @param string $driver the driver base url
 * @return string the session id
 */
function selfselectadvanced_open_driver(string $driver): string {
    $session = selfselectadvanced_wd('POST', "$driver/session", [
        'capabilities' => [
            'alwaysMatch' => [
                'browserName' => 'chrome',
                'acceptInsecureCerts' => true,
                'goog:chromeOptions' => [
                    'args' => [
                        '--headless=new', '--no-sandbox', '--disable-dev-shm-usage',
                        '--window-size=1440,1100', '--hide-scrollbars',
                    ],
                ],
            ],
        ],
    ]);
    $sid = $session['value']['sessionId'] ?? null;
    if (!$sid) {
        cli_error('Could not start a browser session: ' . json_encode($session));
    }

    return (string) $sid;
}

/**
 * Sign one user in through the ordinary login form, submitted from
 * the page rather than by clicking the button (themes position the
 * button differently and a headless click can miss it, while
 * submitting carries the login token exactly as a real sign-in does).
 *
 * @param string $sid the session id
 * @param string $driver the driver base url
 * @param string $wwwroot the site root
 * @param string $username the user to sign in
 * @param string $password their password
 */
function selfselectadvanced_login(string $sid, string $driver, string $wwwroot, string $username, string $password): void {
    selfselectadvanced_wd('POST', "$driver/session/$sid/url", ['url' => "$wwwroot/login/index.php"]);
    usleep(1500000);
    selfselectadvanced_wd('POST', "$driver/session/$sid/execute/sync", [
        'script' => 'document.querySelector("#username").value = arguments[0];'
            . ' document.querySelector("#password").value = arguments[1];'
            . ' document.querySelector("#login").submit();',
        'args' => [$username, $password],
    ]);
    usleep(3000000);
    $landed = (string) (selfselectadvanced_wd('GET', "$driver/session/$sid/url")['value'] ?? '');
    if (str_contains($landed, '/login/')) {
        selfselectadvanced_wd('DELETE', "$driver/session/$sid");
        cli_error("Sign-in as $username did not complete; still at $landed");
    }
}

/**
 * Visit a page, run any scripted interactions, and screenshot it.
 *
 * @param string $sid the session id
 * @param string $driver the driver base url
 * @param array $step the storyboard step
 * @param string $file the target png path
 */
function selfselectadvanced_shoot(string $sid, string $driver, array $step, string $file): void {
    selfselectadvanced_wd('POST', "$driver/session/$sid/url", ['url' => $step['url']]);
    usleep(2000000);
    foreach ($step['script'] ?? [] as $script) {
        selfselectadvanced_wd('POST', "$driver/session/$sid/execute/sync", ['script' => $script, 'args' => []]);
        usleep(($step['settle'] ?? 1) * 1000000);
    }
    // Most pages are longer than the window, and the part worth
    // showing is rarely the top: centre the element the step is
    // actually about before taking the picture.
    if (!empty($step['scrollto'])) {
        selfselectadvanced_wd('POST', "$driver/session/$sid/execute/sync", [
            'script' => 'const el = document.querySelector(arguments[0]);'
                . ' if (el) { el.scrollIntoView({block: "center"}); }',
            'args' => [$step['scrollto']],
        ]);
        usleep(800000);
    }
    $shot = selfselectadvanced_wd('GET', "$driver/session/$sid/screenshot");
    if (!empty($shot['value'])) {
        $written = file_put_contents($file, base64_decode($shot['value']));
        if ($written === false || !file_exists($file)) {
            // Fail loudly: a silently-dropped write (wrong ownership on
            // --out, a full disk) must never be reported as a capture.
            cli_error("Could not write $file - check that --out is writable by the CLI's user.");
        }
        cli_writeln('Captured ' . basename($file) . ' (' . filesize($file) . ' bytes)');
    } else {
        cli_writeln('WARNING: no screenshot for ' . $step['url']);
    }
}

$base = "$wwwroot/mod/selfselectadvanced";
$expandsettings = 'document.querySelectorAll(".fcontainer.collapse, fieldset .collapse")'
    . '.forEach((el) => el.classList.add("show"));'
    . ' document.querySelectorAll(\'[aria-expanded="false"]\')'
    . '.forEach((el) => el.setAttribute("aria-expanded", "true"));';

// Every step names the person who sees it, because the same activity
// reads differently to the administrator, the teacher, a guide and a
// student, and the arc walks through them in that order.
$storyboard = [
    // Part one: the administrator. Both pages are guarded by the
    // system-context ingestattributes capability, held in practice
    // only by real site administrators.
    '01-departments' => [
        'user' => 'howto.admin',
        'url' => "$wwwroot/mod/selfselectadvanced/departments.php",
    ],
    '02-attributes' => [
        'user' => 'howto.admin',
        'url' => "$wwwroot/mod/selfselectadvanced/attributes.php",
    ],
    // Part two: the teacher. The settings form collapses every
    // section but the first, so open them all before scrolling to the
    // section this frame is about.
    '03-settings-group-size' => [
        'user' => 'howto.teacher',
        'url' => "$wwwroot/course/modedit.php?update=$cmid",
        'script' => [$expandsettings],
        'scrollto' => '#id_minsize',
    ],
    '04-settings-guides-eoi' => [
        'user' => 'howto.teacher',
        'url' => "$wwwroot/course/modedit.php?update=$cmid",
        'script' => [$expandsettings],
        'scrollto' => '#id_guidemode',
    ],
    '05-settings-dates-penalties' => [
        'user' => 'howto.teacher',
        'url' => "$wwwroot/course/modedit.php?update=$cmid",
        'script' => [$expandsettings],
        'scrollto' => '#id_timeopen',
    ],
    '06-counting-rule-and-seats' => [
        'user' => 'howto.teacher',
        'url' => "$base/quotas.php?id=$cmid",
    ],
    // Part three: the student forms a team, one action per slide, in
    // the order a student meets them. Comet carries several frames
    // from the same page at a different scroll position - the same
    // pattern teamrecruit's own how-to deck used for one brief.
    '07-create-a-group' => [
        'user' => 'howto.s02',
        'url' => "$base/group.php?id=$cmid&g={$groups['comet']}",
    ],
    '08-invite-a-teammate' => [
        'user' => 'howto.s02',
        'url' => "$base/group.php?id=$cmid&g={$groups['comet']}",
        'scrollto' => '.selfselectadvanced-pendinginvites',
    ],
    '09-composition-panel' => [
        'user' => 'howto.s02',
        'url' => "$base/group.php?id=$cmid&g={$groups['comet']}",
        'scrollto' => '.selfselectadvanced-quotapanel',
    ],
    '10-list-the-team' => [
        'user' => 'howto.s02',
        'url' => "$base/group.php?id=$cmid&g={$groups['comet']}",
        'scrollto' => '.selfselectadvanced-eoi',
    ],
    '11-interest-from-guides' => [
        'user' => 'howto.s02',
        'url' => "$base/group.php?id=$cmid&g={$groups['comet']}",
        'scrollto' => '.selfselectadvanced-eoirows',
    ],
    '12-accept-an-interest' => [
        'user' => 'howto.s03',
        'url' => "$base/group.php?id=$cmid&g={$groups['orbit']}",
        'scrollto' => '.selfselectadvanced-eoi',
    ],
    '13-submit-for-review' => [
        'user' => 'howto.s04',
        'url' => "$base/group.php?id=$cmid&g={$groups['vertex']}",
        'scrollto' => '.selfselectadvanced-proposal',
    ],
    // Part four: the guide.
    '14-browse-listed-teams' => [
        'user' => 'howto.guide2',
        'url' => "$base/pickteam.php?id=$cmid",
    ],
    '15-guide-dashboard' => [
        'user' => 'howto.guide',
        'url' => "$base/guide.php?id=$cmid",
    ],
    '16-interest-list-and-contacts' => [
        'user' => 'howto.guide2',
        'url' => "$base/eoilist.php?id=$cmid&status=accepted&viewgroup={$groups['orbit']}",
    ],
    '17-guide-review' => [
        'user' => 'howto.guide',
        'url' => "$base/review.php?id=$cmid&g={$groups['vertex']}",
    ],
    // Part five: firm and frozen.
    // group.php never shows the mark itself (only the guide's review
    // page does), so this frame is the guide's own view of the firm
    // team, scrolled to the award they gave it. No id marks that
    // section, so the heading text is the handle, the same trick
    // teamrecruit's own how-to capture used.
    '18-firm-with-a-mark' => [
        'user' => 'howto.guide',
        'url' => "$base/review.php?id=$cmid&g={$groups['solstice']}",
        'script' => [
            '[...document.querySelectorAll("h1,h2,h3,h4")]'
            . '.filter((h) => h.textContent.trim().indexOf("Group mark") === 0)'
            . '.forEach((h) => h.scrollIntoView({block: "center"}));',
        ],
    ],
    '19-frozen-team' => [
        'user' => 'howto.s06',
        'url' => "$base/group.php?id=$cmid&g={$groups['meridian']}",
    ],
    // Part six: reports.
    '20-students-with-no-team' => [
        'user' => 'howto.teacher',
        'url' => "$base/flagged.php?id=$cmid",
    ],
    '21-formation-analytics' => [
        'user' => 'howto.teacher',
        'url' => "$base/analytics.php?id=$cmid",
    ],
];

$only = array_filter(array_map('trim', explode(',', (string) $options['only'])));

// One browser session per person rather than logging out in between:
// Moodle refuses a second sign-in while a session is live, and a fresh
// session carries no cookies from the last one, so each capture is
// unambiguously that person's own view.
$bysteps = [];
foreach ($storyboard as $name => $step) {
    if ($only && !in_array($name, $only, true)) {
        continue;
    }
    $bysteps[$step['user']][$name] = $step;
}
foreach ($bysteps as $username => $steps) {
    $sid = selfselectadvanced_open_driver($driver);
    selfselectadvanced_login($sid, $driver, $wwwroot, $username, $pass);
    cli_writeln("--- signed in as $username");
    foreach ($steps as $name => $step) {
        selfselectadvanced_shoot($sid, $driver, $step, "$out/$name.png");
    }
    selfselectadvanced_wd('DELETE', "$driver/session/$sid");
}

cli_writeln('');
cli_writeln("Done. Screenshots in $out");
cli_writeln('Check them: identical file sizes usually mean a sign-in silently failed.');
