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
 * A team approaches a guide (strategy 1.17 E), on a page of its own so
 * the existing submission page is undisturbed.
 *
 * The guides are listed with the things that help a team choose - name,
 * department, sub-department, and how much each is carrying - and with
 * nothing that would let anybody contact anybody directly. The approach
 * itself travels as a Moodle message built from a template.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\groups;

$id = required_param('id', PARAM_INT);
$groupid = required_param('g', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:creategroup', $context);

$group = groups::get($activity, $groupid);
if ((int) $group->leaderid !== (int) $USER->id) {
    throw new moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
}

$api = new \mod_selfselectadvanced\local\api($activity);
$baseurl = new moodle_url('/mod/selfselectadvanced/contact.php', ['id' => $cm->id, 'g' => $groupid]);
$grouppage = new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $groupid]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'send' && data_submitted() && confirm_sesskey()) {
    $guideid = required_param('guide', PARAM_INT);
    $message = optional_param('message', '', PARAM_TEXT);
    try {
        contacts::send($activity, $group, $guideid, $message, FORMAT_PLAIN, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('contactsentnotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contactheading', 'mod_selfselectadvanced', format_string($group->name)));

$remaining = contacts::remaining($activity, $groupid);
echo html_writer::div(
    get_string('contactintro', 'mod_selfselectadvanced', $remaining),
    'alert alert-info'
);

// What this team has already sent, and where each stands.
$sent = contacts::for_group($activity, $groupid);
if ($sent) {
    $guideids = array_map(static fn($c) => (int) $c->guideid, $sent);
    $names = [];
    [$insql, $params] = $DB->get_in_or_equal(array_unique($guideids));
    $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;
    foreach ($DB->get_records_sql("SELECT id{$namefields} FROM {user} WHERE id $insql", $params) as $u) {
        $names[(int) $u->id] = fullname($u);
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable selfselectadvanced-contacts';
    $table->head = [
        get_string('guidelabelplain', 'mod_selfselectadvanced'),
        get_string('status'),
        get_string('contactreasongiven', 'mod_selfselectadvanced'),
    ];
    foreach ($sent as $contact) {
        $status = get_string('contactstatus' . $contact->status, 'mod_selfselectadvanced');
        $reason = trim((string) ($contact->reason ?? ''));
        $table->data[] = [
            s($names[(int) $contact->guideid] ?? ''),
            $status,
            $reason !== '' ? s($reason) : '-',
        ];
    }
    echo $OUTPUT->heading(get_string('contactalready', 'mod_selfselectadvanced'), 4);
    echo html_writer::table($table);
}

if (!empty($group->guideid)) {
    echo html_writer::div(get_string('contacthasguidenotice', 'mod_selfselectadvanced'), 'alert alert-success');
    echo html_writer::link($grouppage, get_string('back'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    die;
}

if ($remaining < 1) {
    echo html_writer::div(get_string('contactnoneleft', 'mod_selfselectadvanced'), 'alert alert-warning');
    echo html_writer::link($grouppage, get_string('back'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    die;
}

// The guides a team may approach, with what helps them choose and
// nothing that identifies anybody beyond their name.
$alreadysent = array_map(static fn($c) => (int) $c->guideid, $sent);
$guides = \mod_selfselectadvanced\local\guides::with_load($activity, $api->gatekeeper()->resolver(), true);
$attrs = \mod_selfselectadvanced\local\attributes\manager::get_for_users(array_keys($guides));

$table = new html_table();
$table->attributes['class'] = 'generaltable selfselectadvanced-guidechoice';
$table->head = [
    get_string('fullname'),
    get_string('department', 'mod_selfselectadvanced'),
    get_string('subdepartment', 'mod_selfselectadvanced'),
    get_string('guideloadused', 'mod_selfselectadvanced'),
    get_string('actions'),
];
foreach ($guides as $guide) {
    $attr = $attrs[(int) $guide->id] ?? null;
    $already = in_array((int) $guide->id, $alreadysent, true);
    $form = $already
        ? html_writer::span(get_string('contactalreadysent', 'mod_selfselectadvanced'), 'text-muted small')
        : html_writer::start_tag('form', ['method' => 'post',
            'action' => (new moodle_url($baseurl, ['action' => 'send']))->out(false),
            'class' => 'd-flex gap-1 align-items-center'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'guide', 'value' => $guide->id])
            . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'message', 'size' => 30,
                'class' => 'form-control form-control-sm',
                'placeholder' => get_string('contactmessagehint', 'mod_selfselectadvanced')])
            . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                'value' => get_string('contactsend', 'mod_selfselectadvanced')])
            . html_writer::end_tag('form');

    $table->data[] = [
        s($guide->fullname),
        s($attr->department ?? '-'),
        s($attr->subdepartment ?? '-'),
        get_string('guideload', 'mod_selfselectadvanced', $guide),
        $form,
    ];
}
echo $OUTPUT->heading(get_string('contactchoose', 'mod_selfselectadvanced'), 4);
echo html_writer::table($table);

echo html_writer::link($grouppage, get_string('back'), ['class' => 'btn btn-secondary']);
echo $OUTPUT->footer();
