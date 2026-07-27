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
$download = optional_param('download', '', PARAM_ALPHA);

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

    // One column per composition dimension the activity actually uses,
    // so the guide sees at a glance how each member satisfies the seat
    // plan; when no rules are configured the two department levels are
    // shown as the sensible default.
    $useddims = \mod_selfselectadvanced\local\attributes\manager::used_dimensions($activity);

    $mq = optional_param('mq', '', PARAM_RAW_TRIMMED);
    $msort = optional_param('msort', 'lastname', PARAM_ALPHANUMEXT);
    $mdir = optional_param('mdir', 0, PARAM_INT);

    $namefields = implode(', ', array_map(
        static fn(string $field) => 'u.' . $field,
        \core_user\fields::for_name()->get_required_fields()
    ));
    $dimselect = implode(', ', array_map(static fn(string $dim) => 'a.' . $dim, $useddims));
    $memberrecords = $DB->get_records_sql(
        "SELECT u.id AS userid, $namefields, u.email, a.mobile, $dimselect
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
        $member = (object) [
            'firstname' => $memberrecord->firstname,
            'lastname' => $memberrecord->lastname,
            'email' => $memberrecord->email,
            'mobile' => (string) ($memberrecord->mobile ?? ''),
            'mailtourl' => 'mailto:' . $memberrecord->email,
            'haswhatsapp' => $digits !== '',
            'whatsappurl' => $digits !== '' ? 'https://wa.me/' . $digits : '',
        ];
        foreach ($useddims as $dim) {
            $member->$dim = (string) ($memberrecord->$dim ?? '');
        }
        $members[] = $member;
        if (!empty($memberrecord->email)) {
            $addresses[] = $memberrecord->email;
        }
    }

    // Text filter across every visible field, then a locale-aware sort
    // on the requested column; the roster is bounded by the group size,
    // so both happen comfortably in PHP.
    $sortable = array_merge(['firstname', 'lastname', 'email', 'mobile'], $useddims);
    if ($mq !== '') {
        $needle = \core_text::strtolower($mq);
        $members = array_values(array_filter($members, static function ($member) use ($needle, $sortable) {
            foreach ($sortable as $field) {
                if (\core_text::strpos(\core_text::strtolower((string) $member->$field), $needle) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
    if (in_array($msort, $sortable, true)) {
        \core_collator::asort_objects_by_property($members, $msort);
        $members = array_values($members);
        if ($mdir) {
            $members = array_reverse($members);
        }
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($group->name));
    echo $OUTPUT->heading(get_string('eoimembers', 'mod_selfselectadvanced'), 3);

    $memberurl = new moodle_url($baseurl, array_filter(['viewgroup' => $viewgroup, 'mq' => $mq]));
    echo html_writer::start_tag('form', ['method' => 'get',
        'action' => $memberurl->out_omit_querystring(), 'class' => 'd-flex flex-wrap gap-2 mb-2']);
    foreach (['id' => $cm->id, 'viewgroup' => $viewgroup, 'status' => $status] as $hname => $hvalue) {
        if ($hvalue !== '' && $hvalue !== 0) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $hname, 'value' => $hvalue]);
        }
    }
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'mq', 'value' => $mq,
        'class' => 'form-control form-control-sm w-auto',
        'placeholder' => get_string('flaggedfilter', 'mod_selfselectadvanced')]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
        'class' => 'btn btn-secondary btn-sm']);
    echo html_writer::end_tag('form');

    $sortlink = static function (string $col, string $label) use ($memberurl, $msort, $mdir): string {
        $url = new moodle_url($memberurl, ['msort' => $col, 'mdir' => ($msort === $col && !$mdir) ? 1 : 0]);
        $arrow = $msort === $col ? ($mdir ? ' &#9660;' : ' &#9650;') : '';
        return html_writer::link($url, $label) . $arrow;
    };
    $table = new html_table();
    $table->head = [
        $sortlink('firstname', get_string('firstname')),
        $sortlink('lastname', get_string('lastname')),
        $sortlink('email', get_string('email')),
        $sortlink('mobile', get_string('attrmobile', 'mod_selfselectadvanced')),
    ];
    foreach ($useddims as $dim) {
        $table->head[] = $sortlink($dim, get_string('attr' . $dim, 'mod_selfselectadvanced'));
    }
    foreach ($members as $member) {
        $contact = html_writer::link($member->mailtourl, $member->email);
        $mobilecell = s($member->mobile);
        if ($member->haswhatsapp) {
            $mobilecell .= ' ' . html_writer::link(
                $member->whatsappurl,
                get_string('eoiwhatsapp', 'mod_selfselectadvanced'),
                ['class' => 'ms-1']
            );
        }
        $row = [s($member->firstname), s($member->lastname), $contact, $mobilecell];
        foreach ($useddims as $dim) {
            $row[] = s($member->$dim);
        }
        $table->data[] = $row;
    }
    $table->attributes['class'] = 'generaltable selfselectadvanced-eoimembers';
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
$heading = isset($statusheadings[$status])
    ? get_string($statusheadings[$status], 'mod_selfselectadvanced')
    : get_string('eoilistheading', 'mod_selfselectadvanced');

$statusoptions = [];
foreach ($validstatuses as $validstatus) {
    $statusoptions[] = (object) [
        'value' => $validstatus,
        'label' => get_string('eoistatus' . $validstatus, 'mod_selfselectadvanced'),
        'selected' => $validstatus === $status,
    ];
}

if ($download !== '') {
    \mod_selfselectadvanced\local\exporter::download(
        'eoi-interests',
        [get_string('groupname', 'mod_selfselectadvanced'), get_string('leader', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'), get_string('timecreated'), get_string('lastmodified'),
            get_string('eoiremarks', 'mod_selfselectadvanced')],
        array_map(
            static fn($r) => [$r->rawname, $r->leader, $r->status, $r->timecreated, $r->timeresponded, $r->remarks],
            \mod_selfselectadvanced\table\eoilist_table::export_rows($activity, (int) $USER->id, $status)
        ),
        $download
    );
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
    \mod_selfselectadvanced\local\exporter::controls($baseurl, '')
    . \mod_selfselectadvanced\local\perpage::controls($baseurl)
    . html_writer::link($guideurl, get_string('back'), ['class' => 'btn btn-secondary ms-2']),
    'd-flex flex-wrap align-items-center gap-2 mt-2'
);
echo $OUTPUT->footer();
