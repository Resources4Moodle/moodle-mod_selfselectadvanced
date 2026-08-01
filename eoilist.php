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
 * plus a member listing per team for any team the guide has ever
 * expressed interest in.
 *
 * NO EMAIL ADDRESS IS RENDERED, LINKED OR EXPORTED HERE, for anybody -
 * a teammate, an assigned guide, an editing teacher alike (maintainer
 * decision 17, 2026-08-01). This page used to be the plugin's only raw
 * address emitter; the address column, the per-member mailto:, the
 * "Email the whole team" button and the contact columns of the download
 * are gone, and staff reach a member through Send a message, which is a
 * Moodle message. A mobile number shows only to a viewer connected to
 * its owner who also consented, and never with an off-platform link
 * while the activity protects contact details.
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

// Member drill-down: ownership is the guide's own LIVE interest in that
// group - the same per-request IDOR guard used throughout the plugin
// (spec 14.12), so a guide cannot browse every team's roster by
// guessing group ids, narrowed in 1.20.1 to interests that are still
// live.
if ($viewgroup > 0) {
    // DECISIONS 19 and 20 (maintainer, 2026-08-01) both live in
    // teamaccess::may_drill_down(), which is where the predicate is
    // written and explained: a live interest only, and an accepted one
    // only while its guide is still the team's assigned guide, so a
    // handover keeps the outgoing guide's sight until acceptance
    // completes it and a staff reassignment ends it at once. Not
    // transcribed here - a unit test of that function is a test of this
    // page's gate.
    if (!\mod_selfselectadvanced\local\teamaccess::may_drill_down($activity, $viewgroup, (int) $USER->id)) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('eoimembers', 'mod_selfselectadvanced'));
    }
    $group = \mod_selfselectadvanced\local\groups::get($activity, $viewgroup);

    // One column per composition dimension the activity actually uses,
    // so the guide sees at a glance how each member satisfies the seat
    // plan; when no rules are configured the two department levels are
    // shown as the sensible default.
    $useddims = \mod_selfselectadvanced\local\attributes\manager::used_dimensions($activity);

    // Mobile visibility is connection AND the member's own consent.
    // The capability asked is the IDENTITY one, never :viewall: seeing
    // every team is not permission to overrule a person's consent, and
    // :viewall is a reach question that no identity decision in this
    // plugin may read any more.
    $hasidentitycap = has_capability('mod/selfselectadvanced:viewparticipantidentity', $context, $USER->id, false);

    $mq = optional_param('mq', '', PARAM_RAW_TRIMMED);
    $msort = optional_param('msort', 'lastname', PARAM_ALPHANUMEXT);
    $mdir = optional_param('mdir', 0, PARAM_INT);
    $memberurl = new moodle_url($baseurl, array_filter(['viewgroup' => $viewgroup, 'mq' => $mq]));

    $namefields = implode(', ', array_map(
        static fn(string $field) => 'u.' . $field,
        \core_user\fields::for_name()->get_required_fields()
    ));
    $dimselect = implode(', ', array_map(static fn(string $dim) => 'a.' . $dim, $useddims));
    // The address column is deliberately NOT selected. An address that
    // is never fetched cannot leak through a later edit, a var_dump or
    // a template that iterates the record.
    $memberrecords = $DB->get_records_sql(
        "SELECT u.id AS userid, $namefields, a.mobile, a.shareconsent, $dimselect
           FROM {selfselectadvanced_member} m
           JOIN {user} u ON u.id = m.userid
      LEFT JOIN {selfselectadvanced_userattr} a ON a.userid = u.id
          WHERE m.groupid = :groupid AND m.status = :confirmed
       ORDER BY m.isleader DESC, u.lastname, u.firstname",
        ['groupid' => $group->id, 'confirmed' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
    );

    // One bulk decision for the whole team, never one per row: the
    // connection map, the consent-bypass verdict, the switch itself and
    // the Send-a-message verdict.
    $memberids = array_map(static fn($r) => (int) $r->userid, $memberrecords);
    $privacymap = \mod_selfselectadvanced\local\contactprivacy::can_see_map($activity, (int) $USER->id, $memberids);
    $mobilebypass = \mod_selfselectadvanced\local\contactprivacy::mobile_consent_bypass(
        $activity,
        (int) $USER->id,
        $hasidentitycap
    );
    $protect = \mod_selfselectadvanced\local\contactprivacy::enabled($activity);
    $messagemap = \mod_selfselectadvanced\local\staffmessage::may_message_map($activity, (int) $USER->id, $memberids);

    $members = [];
    $anymobileshown = false;
    // Whether ANY row can carry an action, decided once for the table.
    // may_message_map() is empty for a viewer who is neither :manage
    // nor :viewall and guides nobody on this roster, and a header over
    // a column of empty cells is an invitation to "fix" it by widening
    // the gate that made it empty.
    $anymessage = (bool) array_filter($messagemap);
    foreach ($memberrecords as $memberrecord) {
        // Manager::mobile_visible() reads only ->shareconsent off the
        // record; the joined member row carries it directly, so it
        // doubles as the attribute record without a second query. The
        // connection map is AND-ed onto it: consent alone is not a
        // reason to show a number to somebody unconnected.
        $mobilevisible = !empty($privacymap[(int) $memberrecord->userid])
            && \mod_selfselectadvanced\local\attributes\manager::mobile_visible($memberrecord, $mobilebypass);
        $rawmobile = (string) ($memberrecord->mobile ?? '');
        // With the switch ON the plugin offers no off-platform contact
        // affordance at all: the number itself may still reach a
        // connected, consenting viewer, but there is no wa.me link.
        $digits = ($mobilevisible && !$protect) ? preg_replace('/\D+/', '', $rawmobile) : '';
        $member = (object) [
            'firstname' => $memberrecord->firstname,
            'lastname' => $memberrecord->lastname,
            'mobile' => $mobilevisible ? $rawmobile : get_string('mobilewithheld', 'mod_selfselectadvanced'),
            'haswhatsapp' => $digits !== '',
            'whatsappurl' => $digits !== '' ? 'https://wa.me/' . $digits : '',
            'messageurl' => !empty($messagemap[(int) $memberrecord->userid])
                ? \mod_selfselectadvanced\local\staffmessage::url(
                    $activity,
                    (int) $memberrecord->userid,
                    $memberurl
                )->out(false)
                : '',
        ];
        if ($mobilevisible && $rawmobile !== '') {
            $anymobileshown = true;
        }
        foreach ($useddims as $dim) {
            $member->$dim = (string) ($memberrecord->$dim ?? '');
        }
        $members[] = $member;
    }

    // Text filter across every visible field, then a locale-aware sort
    // on the requested column; the roster is bounded by the group size,
    // so both happen comfortably in PHP. 'email' is gone from the list
    // because the column is gone: filtering or sorting on a property
    // the objects no longer carry is a warning on every request, and
    // keeping the raw address on the object so the filter still worked
    // would rebuild the oracle the removal just closed.
    $sortable = array_merge(['firstname', 'lastname', 'mobile'], $useddims);
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

    // Export: names and the composition dimensions, and NO contact
    // column of any kind (decision 17). A spreadsheet is the easiest
    // thing in the world to forward, so it is the last place a contact
    // detail should travel.
    if ($download !== '') {
        $columns = [get_string('firstname'), get_string('lastname')];
        foreach ($useddims as $dim) {
            $columns[] = get_string('attr' . $dim, 'mod_selfselectadvanced');
        }
        $exportrows = [];
        foreach ($members as $member) {
            $exportrow = [$member->firstname, $member->lastname];
            foreach ($useddims as $dim) {
                $exportrow[] = $member->$dim;
            }
            $exportrows[] = $exportrow;
        }
        \mod_selfselectadvanced\local\exporter::download('eoi-team-members', $columns, $exportrows, $download);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($group->name));
    echo $OUTPUT->heading(get_string('eoimembers', 'mod_selfselectadvanced'), 3);

    if ($anymobileshown) {
        echo html_writer::tag('p', get_string('mobilecaution', 'mod_selfselectadvanced'), ['class' => 'text-muted small']);
    }

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
        $sortlink('mobile', get_string('attrmobile', 'mod_selfselectadvanced')),
    ];
    foreach ($useddims as $dim) {
        $table->head[] = $sortlink($dim, get_string('attr' . $dim, 'mod_selfselectadvanced'));
    }
    if ($anymessage) {
        $table->head[] = get_string('actions');
    }
    foreach ($members as $member) {
        $mobilecell = s($member->mobile);
        if ($member->haswhatsapp) {
            $mobilecell .= ' ' . html_writer::link(
                $member->whatsappurl,
                get_string('eoiwhatsapp', 'mod_selfselectadvanced'),
                ['class' => 'ms-1']
            );
        }
        $row = [s($member->firstname), s($member->lastname), $mobilecell];
        foreach ($useddims as $dim) {
            $row[] = s($member->$dim);
        }
        // The Send-a-message action that replaces the deleted mailto:
        // link. It opens a form; nothing is sent by following it.
        if ($anymessage) {
            $row[] = $member->messageurl !== ''
                ? html_writer::link(
                    $member->messageurl,
                    get_string('messagesend', 'mod_selfselectadvanced'),
                    ['class' => 'btn btn-outline-secondary btn-sm']
                )
                : '';
        }
        $table->data[] = $row;
    }
    $table->attributes['class'] = 'generaltable selfselectadvanced-eoimembers';
    echo html_writer::table($table);

    echo html_writer::div(
        \mod_selfselectadvanced\local\exporter::controls($memberurl, ''),
        'mt-2'
    );

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
$table = new \mod_selfselectadvanced\table\eoilist_table(
    'ssaeoilist',
    $activity,
    (int) $USER->id,
    $tableurl,
    $status,
    !empty($activity->settings()->eoisequential)
);
$table->out($perpage, false);

echo html_writer::div(
    \mod_selfselectadvanced\local\exporter::controls($baseurl, '')
    . \mod_selfselectadvanced\local\perpage::controls($baseurl)
    . html_writer::link($guideurl, get_string('back'), ['class' => 'btn btn-secondary ms-2']),
    'd-flex flex-wrap align-items-center gap-2 mt-2'
);
echo $OUTPUT->footer();
