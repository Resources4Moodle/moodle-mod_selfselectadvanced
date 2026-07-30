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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Everything waiting for one guide to answer (strategy 1.18 C).
 *
 * A guide's incoming work arrived in three places: teams approaching
 * them behind a link, handover proposals inline on the dashboard, and
 * nothing at all for their own requests. This is the one queue, and
 * because a guide in a large school can be carrying a great many of
 * these it sorts, filters, pages and downloads like every other report
 * in the plugin.
 *
 * The rows come from two services rather than one query, so the sorting
 * and paging happen over the merged array - the same approach the guide
 * load table takes, and for the same reason.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guiderequests_table extends \flexible_table {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url (with active filters)
     * @param string $download '' to render, or the format the reader asked for
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl, string $download = '') {
        parent::__construct($uniqueid);

        $this->define_columns(['kind', 'team', 'detail', 'age', 'action']);
        $this->define_headers([
            get_string('requestkind', 'mod_selfselectadvanced'),
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('requestdetail', 'mod_selfselectadvanced'),
            get_string('requestwaiting', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'age', SORT_DESC);
        $this->no_sorting('action');
        $this->pageable(true);
        $this->collapsible(false);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        if ($download !== '') {
            $this->is_downloading($download, \mod_selfselectadvanced\local\exporter::stamp('guiderequests'));
        }
        $this->set_attribute('class', 'generaltable selfselectadvanced-guiderequests');
        $this->setup();
    }

    /**
     * Everything awaiting one guide's answer, from both services.
     *
     * Built here rather than on the page so the merge - and the
     * timestamp each row sorts by - lives with the table that presents
     * it.
     *
     * @param \mod_selfselectadvanced\activity $activity the activity
     * @param \mod_selfselectadvanced\local\api $api the service facade
     * @param int $guideid the guide
     * @param string $kindfilter '' for everything, or 'contact' or 'handover'
     * @return \stdClass[] rows for display_rows()
     */
    public static function rows_for(
        \mod_selfselectadvanced\activity $activity,
        \mod_selfselectadvanced\local\api $api,
        int $guideid,
        string $kindfilter = ''
    ): array {
        $cmid = (int) $activity->cm()->id;
        $now = time();
        $rows = [];

        if ($kindfilter === '' || $kindfilter === 'contact') {
            foreach (\mod_selfselectadvanced\local\contacts::waiting_for($activity, $guideid) as $contact) {
                $group = \mod_selfselectadvanced\local\groups::get($activity, (int) $contact->groupid);
                $rows[] = (object) [
                    'kind' => 'contact',
                    'kindlabel' => get_string('requestkindcontact', 'mod_selfselectadvanced'),
                    'team' => format_string($group->name)
                        . ' ' . \html_writer::span($group->pluginuid, 'text-muted small'),
                    'detail' => s(shorten_text((string) $contact->message, 140)),
                    'timecreated' => (int) $contact->timecreated,
                    'age' => format_time($now - (int) $contact->timecreated),
                    'action' => \html_writer::link(
                        new \moodle_url('/mod/selfselectadvanced/contactreview.php', [
                            'id' => $cmid,
                            'c' => $contact->id,
                        ]),
                        get_string('contactopen', 'mod_selfselectadvanced'),
                        ['class' => 'btn btn-primary btn-sm']
                    ),
                ];
            }
        }

        if ($kindfilter === '' || $kindfilter === 'handover') {
            foreach ($api->handover()->incoming($guideid) as $group) {
                $proposer = \core_user::get_user((int) $group->guideid);
                $nominated = (int) ($group->timeguidenominated ?? 0);
                $rows[] = (object) [
                    'kind' => 'handover',
                    'kindlabel' => get_string('requestkindhandover', 'mod_selfselectadvanced'),
                    'team' => format_string($group->name)
                        . ' ' . \html_writer::span($group->pluginuid, 'text-muted small'),
                    'detail' => $proposer
                        ? get_string('requesthandoverfrom', 'mod_selfselectadvanced', fullname($proposer))
                        : '',
                    'timecreated' => $nominated,
                    'age' => format_time($now - ($nominated ?: $now)),
                    'action' => \html_writer::link(
                        new \moodle_url('/mod/selfselectadvanced/guide.php', [
                            'id' => $cmid,
                            'guidetab' => 'handover',
                        ]),
                        get_string('requestanswer', 'mod_selfselectadvanced'),
                        ['class' => 'btn btn-secondary btn-sm']
                    ),
                ];
            }
        }

        return $rows;
    }

    /**
     * Sort, page and render the merged queue.
     *
     * @param \stdClass[] $rows kind, kindlabel, team, detail, age, timecreated, action
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $rows = array_values($rows);
        $sort = $this->get_sort_columns();
        if ($sort) {
            usort($rows, static function ($a, $b) use ($sort) {
                foreach ($sort as $column => $direction) {
                    // Waiting time sorts on the timestamp behind it, so
                    // the oldest really is the oldest rather than
                    // whichever rendered phrase sorts first.
                    $result = $column === 'age'
                        ? ((int) $b->timecreated <=> (int) $a->timecreated)
                        : strcasecmp((string) ($a->$column ?? ''), (string) ($b->$column ?? ''));
                    if ($result !== 0) {
                        return $direction === SORT_DESC ? -$result : $result;
                    }
                }

                return 0;
            });
        }

        if ($this->is_downloading()) {
            $page = $rows;
        } else {
            $this->pagesize($perpage, count($rows));
            $page = array_slice($rows, $this->get_page_start(), $this->get_page_size());
        }
        foreach ($page as $row) {
            $this->add_data_keyed([
                'kind' => $row->kindlabel,
                'team' => $row->team,
                'detail' => $row->detail,
                'age' => $row->age,
                // A download of a queue is a record of what was waiting,
                // not a sheet of buttons that no longer work.
                'action' => $this->is_downloading() ? '' : $row->action,
            ]);
        }
        $this->finish_output();
    }
}
