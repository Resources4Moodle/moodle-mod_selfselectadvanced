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

namespace mod_selfselectadvanced\local;

/**
 * User-selectable rows-per-page for every paginated report/listing in
 * the plugin (audit request 2026-07-26): the choice is remembered per
 * user through a Moodle user preference, so it sticks across pages and
 * visits until the user picks a different size.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class perpage {
    /** @var int[] Offered page sizes. */
    public const OPTIONS = [10, 20, 50, 100, 200];

    /**
     * The page size to use for the current request, remembered for the
     * user once a valid choice is submitted.
     *
     * @param int $default fallback when no valid choice or preference exists
     * @return int
     */
    public static function current(int $default = 50): int {
        $requested = optional_param('perpage', 0, PARAM_INT);
        if (in_array($requested, self::OPTIONS, true)) {
            set_user_preference('mod_selfselectadvanced_perpage', $requested);
            return $requested;
        }

        $stored = (int) get_user_preference('mod_selfselectadvanced_perpage', $default);
        return in_array($stored, self::OPTIONS, true) ? $stored : $default;
    }

    /**
     * A GET page-size selector + submit button pair for a paginated
     * listing, meant to sit next to a page's export controls (if any).
     *
     * @param \moodle_url $url the page url carrying the listing's other params
     * @return string html
     */
    public static function controls(\moodle_url $url): string {
        $options = [];
        foreach (self::OPTIONS as $size) {
            $options[$size] = (string) $size;
        }

        $html = \html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(),
            'class' => 'd-inline-flex gap-2 align-items-center']);
        foreach ($url->params() as $name => $value) {
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        $html .= \html_writer::label(
            get_string('perpage', 'mod_selfselectadvanced'),
            'ssa-perpage',
            true,
            ['class' => 'me-1']
        );
        $html .= \html_writer::select(
            $options,
            'perpage',
            self::current(),
            false,
            ['id' => 'ssa-perpage', 'class' => 'form-select w-auto d-inline-block me-1']
        );
        $html .= \html_writer::empty_tag('input', ['type' => 'submit',
            'value' => get_string('perpageapply', 'mod_selfselectadvanced'), 'class' => 'btn btn-secondary']);
        $html .= \html_writer::end_tag('form');

        return $html;
    }
}
