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
 * Notification templates page: per-activity subject/body overrides.
 *
 * Guarded by mod/selfselectadvanced:manage (editing teachers by
 * default). Unset kinds fall back to the language strings.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\templates;

$id = required_param('id', PARAM_INT);
$msgkey = optional_param('k', '', PARAM_ALPHANUMEXT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, false, $cm);
$activity = activity::from_cmid($cm->id);
require_capability('mod/selfselectadvanced:manage', $activity->context());

$baseurl = new moodle_url('/mod/selfselectadvanced/templates.php', ['id' => $id]);
$PAGE->set_url($msgkey === '' ? $baseurl : new moodle_url($baseurl, ['k' => $msgkey]));
$PAGE->set_title(get_string('notificationtemplates', 'mod_selfselectadvanced'));
$PAGE->set_heading(format_string($course->fullname));

if ($msgkey !== '') {
    if (!isset(templates::CATALOG[$msgkey])) {
        throw new moodle_exception('errtemplatekey', 'mod_selfselectadvanced');
    }
    $subjectkey = templates::CATALOG[$msgkey];
    $existing = templates::get($activity, $msgkey);
    $form = new \mod_selfselectadvanced\form\template_form(new moodle_url($baseurl, ['k' => $msgkey]), [
        'msgkey' => $msgkey,
        'defaultsubject' => get_string_manager()->get_string($subjectkey, 'mod_selfselectadvanced', null),
        'defaultbody' => get_string_manager()->get_string($msgkey, 'mod_selfselectadvanced', null),
    ]);
    if ($existing) {
        $form->set_data(['subject' => $existing->subject, 'body' => $existing->body]);
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        if (!empty($data->resetdefault)) {
            templates::reset($activity, $msgkey, (int) $USER->id);
        } else {
            templates::save($activity, $msgkey, trim($data->subject), trim($data->body), (int) $USER->id);
        }
        redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('notificationtemplates', 'mod_selfselectadvanced'));
    echo $OUTPUT->notification(get_string('templateplaceholdershelp', 'mod_selfselectadvanced'), 'info', false);
    $form->display();
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('notificationtemplates', 'mod_selfselectadvanced'));
echo $OUTPUT->notification(get_string('templatesintro', 'mod_selfselectadvanced'), 'info', false);

$overrides = templates::get_all($activity);
$table = new html_table();
$table->head = [
    get_string('templatekind', 'mod_selfselectadvanced'),
    get_string('subject', 'mod_selfselectadvanced'),
    get_string('templatestatus', 'mod_selfselectadvanced'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable selfselectadvanced-templates';
foreach (templates::CATALOG as $bodykey => $subjectkey) {
    $custom = $overrides[$bodykey] ?? null;
    $subject = $custom
        ? $custom->subject
        : get_string_manager()->get_string($subjectkey, 'mod_selfselectadvanced', null);
    $table->data[] = [
        get_string('tpl' . $bodykey, 'mod_selfselectadvanced'),
        s(shorten_text($subject, 80)),
        $custom
            ? html_writer::span(get_string('templatecustomised', 'mod_selfselectadvanced'), 'badge bg-info text-dark')
            : get_string('templatedefault', 'mod_selfselectadvanced'),
        html_writer::link(new moodle_url($baseurl, ['k' => $bodykey]), get_string('edit')),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
