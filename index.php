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
 * List all mod_selfselectadvanced instances in a course.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT); // Course id.

$course = get_course($id);
require_course_login($course);

$event = \mod_selfselectadvanced\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/selfselectadvanced/index.php', ['id' => $course->id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_selfselectadvanced'));

$instances = get_all_instances_in_course('selfselectadvanced', $course);
if (empty($instances)) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_selfselectadvanced')),
        new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
if ($usesections) {
    $table->head = [get_string('sectionname', 'format_' . $course->format), get_string('name')];
    $table->align = ['left', 'left'];
} else {
    $table->head = [get_string('name')];
    $table->align = ['left'];
}

foreach ($instances as $instance) {
    $class = $instance->visible ? null : ['class' => 'dimmed'];
    $link = html_writer::link(
        new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name, true),
        $class
    );
    if ($usesections) {
        $table->data[] = [get_section_name($course, $instance->section), $link];
    } else {
        $table->data[] = [$link];
    }
}

echo html_writer::table($table);
echo $OUTPUT->footer();
