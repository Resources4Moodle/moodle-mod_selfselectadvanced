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
 * The flagged report's quota tab (audit round 6 item 6). Compliance is
 * a PHP-only check (quota\evaluator::is_compliant()), so the row array
 * is still built by the caller; this class only gives that array a
 * native sortable, paginated look instead of a hand-rolled html_table
 * with manual sort links.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flagged_quota_table extends \flexible_table {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url (with active filters)
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl) {
        parent::__construct($uniqueid);

        $this->define_columns(['name', 'pluginuid', 'state']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        $this->no_sorting('pluginuid');
        $this->pageable(true);
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-quotafail');
        $this->setup();
    }

    /**
     * Sort (per the current tsort/tdir preferences), paginate and
     * render an already-filtered array of quota-failing groups.
     *
     * @param \stdClass[] $rows entries with name, pluginuid, statelabel (see flagged.php)
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $sort = $this->get_sort_columns();
        if ($sort) {
            // Locale-aware stable sort: apply the requested columns in
            // reverse priority order so the primary column decides last.
            foreach (array_reverse($sort, true) as $column => $direction) {
                $key = $column === 'state' ? 'statelabel' : $column;
                \core_collator::asort_objects_by_property($rows, $key);
                $rows = array_values($rows);
                if ($direction === SORT_DESC) {
                    $rows = array_reverse($rows);
                }
            }
        }

        $this->pagesize($perpage, count($rows));
        foreach (array_slice($rows, $this->get_page_start(), $this->get_page_size()) as $row) {
            $this->add_data_keyed([
                'name' => $row->name,
                'pluginuid' => $row->pluginuid,
                'state' => $row->statelabel,
            ]);
        }
        $this->finish_output();
    }
}
