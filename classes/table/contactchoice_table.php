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

namespace mod_selfselectadvanced\table;

use mod_selfselectadvanced\activity;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * The guides a team may approach (strategy 1.18 B).
 *
 * The chooser rendered every guide in the activity with a message box
 * and a button on each row. At 1500 guides that is 1500 forms on one
 * page; it now filters and pages like every other list in the plugin.
 *
 * A table rather than the searchable picker used elsewhere, because
 * here the list IS the decision: the team is comparing departments and
 * loads before choosing, not confirming a name they already know.
 *
 * No address appears, for either party - the 1.17 rule for approaches,
 * which this page must keep holding.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contactchoice_table extends \flexible_table {
    /** @var activity The activity. */
    private activity $activity;

    /** @var int The team doing the approaching. */
    private int $groupid;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param int $groupid the team
     * @param \moodle_url $baseurl page url (with the active filter)
     */
    public function __construct(string $uniqueid, activity $activity, int $groupid, \moodle_url $baseurl) {
        parent::__construct($uniqueid);

        $this->activity = $activity;
        $this->groupid = $groupid;

        $this->define_columns(['fullname', 'department', 'subdepartment', 'load', 'action']);
        $this->define_headers([
            get_string('fullname'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
            get_string('guideloadused', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'fullname');
        $this->no_sorting('action');
        $this->no_sorting('load');
        $this->pageable(true);
        $this->collapsible(false);
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-guidechoice');
        $this->setup();
    }

    /**
     * Sort, page and render the choices.
     *
     * @param \stdClass[] $rows id, fullname, department, subdepartment, load, already
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $rows = array_values($rows);
        $sort = $this->get_sort_columns();
        if ($sort) {
            usort($rows, static function ($a, $b) use ($sort) {
                foreach ($sort as $column => $direction) {
                    $result = strcasecmp((string) ($a->$column ?? ''), (string) ($b->$column ?? ''));
                    if ($result !== 0) {
                        return $direction === SORT_DESC ? -$result : $result;
                    }
                }

                return 0;
            });
        }

        $this->pagesize($perpage, count($rows));
        foreach (array_slice($rows, $this->get_page_start(), $this->get_page_size()) as $row) {
            $this->add_data_keyed([
                'fullname' => s($row->fullname),
                'department' => $row->department !== '' ? s($row->department) : '-',
                'subdepartment' => $row->subdepartment !== '' ? s($row->subdepartment) : '-',
                'load' => $row->load,
                'action' => $this->action($row),
            ]);
        }
        $this->finish_output();
    }

    /**
     * The message box and send button, or a note that this guide has
     * already been approached.
     *
     * @param \stdClass $row the row
     * @return string
     */
    private function action(\stdClass $row): string {
        if (!empty($row->already)) {
            return \html_writer::span(get_string('contactalreadysent', 'mod_selfselectadvanced'), 'text-muted small');
        }
        $url = new \moodle_url('/mod/selfselectadvanced/contact.php', [
            'id' => $this->activity->cm()->id,
            'g' => $this->groupid,
            'action' => 'send',
        ]);

        return \html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false),
            'class' => 'd-flex gap-1 align-items-center'])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'guide', 'value' => (int) $row->id])
            . \html_writer::empty_tag('input', ['type' => 'text', 'name' => 'message', 'size' => 30,
                'class' => 'form-control form-control-sm',
                'placeholder' => get_string('contactmessagehint', 'mod_selfselectadvanced')])
            . \html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                'value' => get_string('contactsend', 'mod_selfselectadvanced')])
            . \html_writer::end_tag('form');
    }
}
