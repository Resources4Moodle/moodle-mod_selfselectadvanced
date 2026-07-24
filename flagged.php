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
 * The flagged report (spec 9.4, 11, 8.1, 4A.8, M1): groupless
 * students awaiting placement, students with missing attributes,
 * leaderless groups, and grandfathered out-of-limit groups.
 * Read-only GET; placement happens via staged moves.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:viewall', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$resolver = $api->gatekeeper()->resolver();

$PAGE->set_url('/mod/selfselectadvanced/flagged.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Groupless students: enrolled respond-holders with no confirmed row.
$enrolled = get_enrolled_users($context, 'mod/selfselectadvanced:respond', 0, 'u.*', 'lastname, firstname');
$confirmedids = $DB->get_fieldset_sql(
    "SELECT DISTINCT m.userid
       FROM {selfselectadvanced_member} m
       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
      WHERE g.activityid = ? AND m.status = ?",
    [$activity->id(), \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
);
$attrs = \mod_selfselectadvanced\local\attributes\manager::get_for_users(array_keys($enrolled));
$groupless = [];
$missingattrs = [];
foreach ($enrolled as $user) {
    $attrline = \mod_selfselectadvanced\local\attributes\manager::display_line(
        $attrs[(int) $user->id] ?? null,
        true
    );
    if (!in_array((int) $user->id, array_map('intval', $confirmedids), true)) {
        $groupless[] = (object) [
            'fullname' => fullname($user),
            'attrline' => $attrline,
            'placeurl' => (new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]))->out(false),
        ];
    }
    if (!isset($attrs[(int) $user->id])) {
        $missingattrs[] = (object) ['fullname' => fullname($user)];
    }
}

// Group anomalies: leaderless (M1) and out-of-limit grandfathered (4A.8).
$anomalies = [];
foreach ($DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()]) as $group) {
    $issues = [];
    if (empty($group->leaderid)) {
        $issues[] = get_string('flagleaderless', 'mod_selfselectadvanced');
    }
    $confirmed = \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id);
    $seats = \mod_selfselectadvanced\local\groups::count_seats_taken((int) $group->id);
    $min = $resolver->effective_minsize((int) $group->id)->value;
    $max = $resolver->effective_maxsize((int) $group->id)->value;
    if ($confirmed < $min || $seats > $max) {
        $issues[] = get_string('flagoutoflimit', 'mod_selfselectadvanced', (object) [
            'confirmed' => $confirmed,
            'seats' => $seats,
            'min' => $min,
            'max' => $max,
        ]);
    }
    if ($issues) {
        $anomalies[] = (object) [
            'name' => format_string($group->name),
            'pluginuid' => $group->pluginuid,
            'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
            'issues' => implode(' ', $issues),
            'url' => (new moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cm->id,
                'g' => $group->id,
            ]))->out(false),
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/flagged_report', (object) [
    'groupless' => $groupless,
    'hasgroupless' => !empty($groupless),
    'grouplesscount' => count($groupless),
    'missingattrs' => $missingattrs,
    'hasmissingattrs' => !empty($missingattrs),
    'anomalies' => $anomalies,
    'hasanomalies' => !empty($anomalies),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]))->out(false),
]);
// Defaulters (1.4.0): students below the minimum memberships.
$minmembership = (int) $activity->settings()->minmembership;
if ($minmembership > 0) {
    echo $OUTPUT->heading(get_string('defaulters', 'mod_selfselectadvanced'), 3);
    $counts = $DB->get_records_sql_menu(
        "SELECT m.userid, COUNT(1)
           FROM {selfselectadvanced_member} m
           JOIN {selfselectadvanced_group} g ON g.id = m.groupid
          WHERE g.activityid = ? AND m.status = ?
       GROUP BY m.userid",
        [$activity->id(), \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
    );
    $rows = [];
    foreach (get_enrolled_users($context, 'mod/selfselectadvanced:respond', 0, 'u.*', 'lastname, firstname') as $student) {
        $have = (int) ($counts[$student->id] ?? 0);
        if ($have < $minmembership) {
            $rows[] = [
                fullname($student),
                $have,
                $minmembership - $have,
            ];
        }
    }
    if ($rows) {
        $dtable = new html_table();
        $dtable->head = [
            get_string('member', 'mod_selfselectadvanced'),
            get_string('defaultershas', 'mod_selfselectadvanced'),
            get_string('defaultersmissing', 'mod_selfselectadvanced'),
        ];
        $dtable->data = $rows;
        $dtable->attributes['class'] = 'generaltable selfselectadvanced-defaulters';
        echo html_writer::table($dtable);
        echo $OUTPUT->notification(get_string('defaultersintro', 'mod_selfselectadvanced'), 'info', false);
    } else {
        echo $OUTPUT->notification(get_string('defaultersnone', 'mod_selfselectadvanced'), 'success', false);
    }
}

echo $OUTPUT->footer();
