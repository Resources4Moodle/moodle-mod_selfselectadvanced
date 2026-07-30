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
 * How much each guide is carrying (strategy 1.17 C1).
 *
 * The loads used to render as a bare list below two other bare lists,
 * where a school with hundreds of guides could neither sort it nor find
 * anybody in it. The figures come from guides::with_load(), which reads
 * them in bulk, so this sorts and pages the array it is handed rather
 * than issuing its own queries.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guideloads_table extends \flexible_table {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url (with active filters)
     * @param string $download '' to render, or the format the reader asked for
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl, string $download = '') {
        parent::__construct($uniqueid);

        $this->define_columns(['fullname', 'used', 'max', 'remaining']);
        $this->define_headers([
            get_string('fullname'),
            get_string('guideloadused', 'mod_selfselectadvanced'),
            get_string('guideloadmax', 'mod_selfselectadvanced'),
            get_string('guideloadremaining', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'fullname');
        $this->pageable(true);
        // Downloadable since 1.18 (strategy F): who is carrying what is
        // a figure people take into meetings, and re-keying it off a
        // paged screen is how it arrives wrong.
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        if ($download !== '') {
            $this->is_downloading($download, \mod_selfselectadvanced\local\exporter::stamp('guideloads'));
        }
        $this->set_attribute('class', 'generaltable selfselectadvanced-guideloads');
        // Its own control variables: this table shares a page with the
        // group list and the two assignment tables, and must not move
        // when one of those is sorted or paged.
        $this->set_control_variables([
            TABLE_VAR_SORT => 'glsort',
            TABLE_VAR_DIR => 'gldir',
            TABLE_VAR_PAGE => 'glpage',
        ]);
        $this->setup();
    }

    /**
     * Sort, page and render the loads.
     *
     * @param \stdClass[] $rows entries with fullname, used, max, remaining
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $rows = array_values($rows);
        $sort = $this->get_sort_columns();
        if ($sort) {
            usort($rows, static function ($a, $b) use ($sort) {
                foreach ($sort as $column => $direction) {
                    $result = in_array($column, ['used', 'max', 'remaining'], true)
                        ? ((int) $a->$column <=> (int) $b->$column)
                        : strcasecmp((string) $a->$column, (string) $b->$column);
                    if ($result !== 0) {
                        return $direction === SORT_DESC ? -$result : $result;
                    }
                }

                return 0;
            });
        }

        // A download is the whole filtered set, not the page on screen.
        if ($this->is_downloading()) {
            $page = $rows;
        } else {
            $this->pagesize($perpage, count($rows));
            $page = array_slice($rows, $this->get_page_start(), $this->get_page_size());
        }
        foreach ($page as $row) {
            $this->add_data_keyed([
                'fullname' => s($row->fullname),
                'used' => (int) $row->used,
                'max' => (int) $row->max,
                'remaining' => (int) $row->remaining,
            ]);
        }
        $this->finish_output();
    }
}
