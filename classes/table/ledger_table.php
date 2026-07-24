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
 * The penalty ledger listing (spec 11): core table_sql with native
 * sorting, paging and download so per-group penalties can feed
 * external or manual grading (C12).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger_table extends \table_sql {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param int $activityid the activity instance id
     * @param \moodle_url $baseurl page url
     * @param bool $download whether a download is in progress
     */
    public function __construct(string $uniqueid, int $activityid, \moodle_url $baseurl, bool $download) {
        parent::__construct($uniqueid);

        $this->define_columns(['name', 'pluginuid', 'state', 'timeapproved', 'dayslate', 'penaltyvalue', 'waived']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('ledgerapproved', 'mod_selfselectadvanced'),
            get_string('ledgerdayslate', 'mod_selfselectadvanced'),
            get_string('ledgerpenalty', 'mod_selfselectadvanced'),
            get_string('ledgerwaived', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'penaltyvalue', SORT_DESC);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $this->set_sql(
            'p.id, g.name, g.pluginuid, g.state, g.timeapproved, p.dayslate, p.penaltyvalue, p.waived, p.waivereason',
            '{selfselectadvanced_penalty} p JOIN {selfselectadvanced_group} g ON g.id = p.groupid',
            'p.activityid = :activityid',
            ['activityid' => $activityid]
        );
    }

    /**
     * Format the group name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_name($row) {
        return format_string($row->name);
    }

    /**
     * Localise the state.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_state($row) {
        return get_string('state' . str_replace('_', '', $row->state), 'mod_selfselectadvanced');
    }

    /**
     * Format the approval time.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timeapproved($row) {
        return $row->timeapproved ? userdate($row->timeapproved) : '';
    }

    /**
     * Format the penalty value.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_penaltyvalue($row) {
        return format_float((float) $row->penaltyvalue, 2);
    }

    /**
     * Waiver column with the recorded reason.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_waived($row) {
        if (!$row->waived) {
            return get_string('no');
        }

        return get_string('ledgerwaivereason' . $row->waivereason, 'mod_selfselectadvanced');
    }
}
