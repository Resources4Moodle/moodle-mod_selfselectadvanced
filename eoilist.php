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
 * A guide's own expressions of interest (EOI 1.11.0): the drill-down
 * behind the guide dashboard's pending/timed-out/declined stat cards,
 * plus a member listing per team (mailto, WhatsApp, mail-the-whole-team)
 * for any team the guide has ever expressed interest in.
 *
 * Read-only GET throughout; the leader's accept/reject decision and the
 * guide's own express/withdraw actions live elsewhere (pickteam.php,
 * group.php).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$viewgroup = optional_param('viewgroup', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:guide', $context);

$guideurl = new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);

// Every surface of the EOI feature respects the master switch, drill-downs included.
if (empty($activity->settings()->eoienabled)) {
    redirect($guideurl, get_string('refusaleoidisabled', 'mod_selfselectadvanced'), null, \core\output\notification::NOTIFY_ERROR);
}

$validstatuses = [
    \mod_selfselectadvanced\local\eoi::STATUS_PENDING,
    \mod_selfselectadvanced\local\eoi::STATUS_ACCEPTED,
    \mod_selfselectadvanced\local\eoi::STATUS_REJECTED,
    \mod_selfselectadvanced\local\eoi::STATUS_EXPIRED,
    \mod_selfselectadvanced\local\eoi::STATUS_WITHDRAWN,
];
if ($status !== '' && !in_array($status, $validstatuses, true)) {
    $status = '';
}

$baseurl = new moodle_url('/mod/selfselectadvanced/eoilist.php', array_filter(['id' => $cm->id, 'status' => $status]));
$perpage = \mod_selfselectadvanced\local\perpage::current(20);

$PAGE->set_url(new moodle_url($baseurl, $viewgroup > 0 ? ['viewgroup' => $viewgroup] : []));
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Withdrawing a pending interest: GET renders the confirm step, POST
// with sesskey performs it. Ownership is enforced inside
// eoi::withdraw(), which requires the row to be this guide's own and
// still pending.
$withdrawaction = optional_param('action', '', PARAM_ALPHA);
if ($withdrawaction === 'withdraw') {
    $eoiid = required_param('eoiid', PARAM_INT);
    $row = \mod_selfselectadvanced\local\eoi::get($activity, $eoiid);
    $rowgroup = \mod_selfselectadvanced\local\groups::get($activity, (int) $row->groupid);

    if (data_submitted() && confirm_sesskey()) {
        try {
            \mod_selfselectadvanced\local\eoi::withdraw($activity, $eoiid, (int) $USER->id);
            redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('eoiwithdrawconfirm', 'mod_selfselectadvanced', format_string($rowgroup->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'withdraw', 'eoiid' => $eoiid]),
            get_string('eoiwithdraw', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

// Member drill-down: ownership is the guide's OWN interest in that
// group, any status - the same per-request IDOR guard used throughout
// the plugin (spec 14.12), so a guide cannot browse every team's
// contact details by guessing group ids.
if ($viewgroup > 0) {
    $hasowninterest = $DB->record_exists('selfselectadvanced_eoi', [
        'activityid' => $activity->id(),
        'groupid' => $viewgroup,
        'guideid' => $USER->id,
    ]);
    if (!$hasowninterest) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('eoimembers', 'mod_selfselectadvanced'));
    }
    $group = \mod_selfselectadvanced\local\groups::get($activity, $viewgroup);

    $namefields = implode(', ', array_map(
        static fn(string $field) => 'u.' . $field,
        \core_user\fields::for_name()->get_required_fields()
    ));
    $memberrecords = $DB->get_records_sql(
        "SELECT u.id AS userid, $namefields, u.email, a.mobile
           FROM {selfselectadvanced_member} m
           JOIN {user} u ON u.id = m.userid
      LEFT JOIN {selfselectadvanced_userattr} a ON a.userid = u.id
          WHERE m.groupid = :groupid AND m.status = :confirmed
       ORDER BY m.isleader DESC, u.lastname, u.firstname",
        ['groupid' => $group->id, 'confirmed' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
    );

    $members = [];
    $addresses = [];
    foreach ($memberrecords as $memberrecord) {
        $digits = preg_replace('/\D+/', '', (string) ($memberrecord->mobile ?? ''));
        $members[] = (object) [
            'fullname' => fullname($memberrecord),
            'email' => $memberrecord->email,
            'mailtourl' => 'mailto:' . $memberrecord->email,
            'haswhatsapp' => $digits !== '',
            'whatsappurl' => $digits !== '' ? 'https://wa.me/' . $digits : '',
        ];
        if (!empty($memberrecord->email)) {
            $addresses[] = $memberrecord->email;
        }
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($group->name));
    echo $OUTPUT->heading(get_string('eoimembers', 'mod_selfselectadvanced'), 4);

    $table = new html_table();
    $table->head = [get_string('fullname'), get_string('email')];
    foreach ($members as $member) {
        $links = html_writer::link($member->mailtourl, $member->email);
        if ($member->haswhatsapp) {
            $links .= ' ' . html_writer::link(
                $member->whatsappurl,
                get_string('eoiwhatsapp', 'mod_selfselectadvanced'),
                ['class' => 'ms-2']
            );
        }
        $table->data[] = [s($member->fullname), $links];
    }
    echo html_writer::table($table);

    if ($addresses) {
        echo html_writer::div(
            html_writer::link(
                'mailto:' . implode(',', $addresses),
                get_string('eoimailall', 'mod_selfselectadvanced'),
                ['class' => 'btn btn-secondary btn-sm']
            ),
            'mt-2'
        );
    }

    echo html_writer::div(
        html_writer::link($baseurl, get_string('back'), ['class' => 'btn btn-secondary mt-3']),
        'mt-2'
    );
    echo $OUTPUT->footer();
    die;
}

// Heading: the three stat cards on guide.php link here with a status
// already narrowed to exactly the wording used on the card they came
// from, so the drill-down and the card read as the same thing.
$statusheadings = [
    \mod_selfselectadvanced\local\eoi::STATUS_PENDING => 'eoicardpending',
    \mod_selfselectadvanced\local\eoi::STATUS_EXPIRED => 'eoicardexpired',
    \mod_selfselectadvanced\local\eoi::STATUS_REJECTED => 'eoicardrejected',
    \mod_selfselectadvanced\local\eoi::STATUS_ACCEPTED => 'eoistatusaccepted',
    \mod_selfselectadvanced\local\eoi::STATUS_WITHDRAWN => 'eoistatuswithdrawn',
];
$heading = isset($statusheadings[$status]) ? get_string($statusheadings[$status], 'mod_selfselectadvanced') : $activity->name();

$statusoptions = [];
foreach ($validstatuses as $validstatus) {
    $statusoptions[] = (object) [
        'value' => $validstatus,
        'label' => get_string('eoistatus' . $validstatus, 'mod_selfselectadvanced'),
        'selected' => $validstatus === $status,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);

echo html_writer::start_tag('form', ['method' => 'get',
    'action' => $baseurl->out_omit_querystring(), 'class' => 'd-flex flex-wrap gap-2 align-items-end mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::label(get_string('state', 'mod_selfselectadvanced'), 'ssa-eoistatus', true, ['class' => 'me-1']);
echo html_writer::start_tag('select', [
    'name' => 'status', 'id' => 'ssa-eoistatus', 'class' => 'form-select form-select-sm w-auto me-2',
]);
echo html_writer::tag('option', get_string('all'), ['value' => '']);
foreach ($statusoptions as $statusoption) {
    echo html_writer::tag(
        'option',
        $statusoption->label,
        array_filter(['value' => $statusoption->value, 'selected' => $statusoption->selected ? 'selected' : null])
    );
}
echo html_writer::end_tag('select');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-secondary btn-sm']);
echo html_writer::end_tag('form');

$tableurl = new moodle_url($baseurl, ['perpage' => $perpage]);
$table = new \mod_selfselectadvanced\table\eoilist_table('ssaeoilist', $activity, (int) $USER->id, $tableurl, $status);
$table->out($perpage, false);

echo html_writer::div(
    \mod_selfselectadvanced\local\perpage::controls($baseurl)
    . html_writer::link($guideurl, get_string('back'), ['class' => 'btn btn-secondary ms-2']),
    'd-flex flex-wrap align-items-center gap-2 mt-2'
);
echo $OUTPUT->footer();
