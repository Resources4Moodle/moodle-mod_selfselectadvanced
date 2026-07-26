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
 * The flagged report's students tab: the "Attributes missing" section.
 * Compliance data (enrolled respond-holders with no attribute record)
 * is PHP-built in flagged.php, so this class mirrors
 * flagged_quota_table: it only gives that array a native sortable,
 * paginated look instead of a bare list.
 *
 * Its sort and page GET parameters are remapped away from the
 * defaults so they do not collide with the groupless list's own
 * tsort/tdir/page handling, or with the anomalies table below it: all
 * three share the students tab page at once.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flagged_missingattrs_table extends \flexible_table {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url (with active filters)
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl) {
        parent::__construct($uniqueid);

        $this->define_columns(['fullname']);
        $this->define_headers([
            get_string('member', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'fullname');
        $this->pageable(true);
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-missingattrs');
        // Distinct control variable names: this table shares the
        // students tab page with the groupless list's own tsort/tdir/
        // page GET params and with the anomalies table, so it must not
        // clash with either.
        $this->set_control_variables([
            TABLE_VAR_SORT => 'masort',
            TABLE_VAR_DIR => 'madir',
            TABLE_VAR_PAGE => 'mapage',
        ]);
        $this->setup();
    }

    /**
     * Sort (per the current sort preferences), paginate and render an
     * already-filtered array of users missing attributes.
     *
     * @param \stdClass[] $rows entries with fullname (see flagged.php)
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $sort = $this->get_sort_columns();
        if ($sort) {
            usort($rows, static function ($a, $b) use ($sort) {
                foreach ($sort as $column => $direction) {
                    $result = strcasecmp((string) $a->$column, (string) $b->$column);
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
                'fullname' => $row->fullname,
            ]);
        }
        $this->finish_output();
    }
}
