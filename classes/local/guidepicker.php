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
 * The one searchable guide picker every page uses (strategy 1.18 B).
 *
 * Rendering it in one place is the point. The control has to ship with
 * NO options - that is the whole reason it exists, a 1500-guide list
 * being unrenderable, and worse once per row of the assignment queue -
 * and a picker built by hand somewhere else would sooner or later be
 * built with its options filled in, which is the bug this prevents.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guidepicker {
    /**
     * The markup for one picker.
     *
     * Degrades cleanly without JavaScript: what is left is a plain
     * select carrying the current choice, which submits unchanged.
     *
     * @param string $name form field name
     * @param int $cmid course module id, for the search
     * @param int $selected currently chosen guide, 0 for none
     * @param string $selectedlabel label for the current choice, shown before any search
     * @param bool $withroom offer only guides with capacity left
     * @param string $elementid explicit element id; one is derived from the name when omitted
     * @return string the select element
     */
    public static function render(
        string $name,
        int $cmid,
        int $selected = 0,
        string $selectedlabel = '',
        bool $withroom = true,
        string $elementid = ''
    ): string {
        global $PAGE;

        self::require_js($PAGE);

        $elementid = $elementid !== '' ? $elementid : 'ssa-guidepicker-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
        $options = '';
        if ($selected > 0) {
            // Only the current choice is rendered. Everything else
            // arrives from the search.
            $options = \html_writer::tag('option', s($selectedlabel), ['value' => $selected, 'selected' => 'selected']);
        }

        return \html_writer::tag('select', $options, [
            'name' => $name,
            'id' => $elementid,
            'class' => 'form-select form-select-sm selfselectadvanced-guidepicker',
            'data-ssa-guidepicker' => '1',
            'data-cmid' => $cmid,
            'data-withroom' => $withroom ? '1' : '0',
        ]);
    }

    /**
     * Register the page requirement, once per page however many pickers
     * it carries.
     *
     * @param \moodle_page $page the page
     */
    public static function require_js(\moodle_page $page): void {
        static $done = [];

        $key = spl_object_id($page);
        if (isset($done[$key])) {
            return;
        }
        $done[$key] = true;

        $page->requires->js_call_amd('mod_selfselectadvanced/guideselector', 'init', [
            get_string('guidepickerplaceholder', 'mod_selfselectadvanced'),
            get_string('guidepickernone', 'mod_selfselectadvanced'),
        ]);
    }
}
