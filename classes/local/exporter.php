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
 * Report downloads through Moodle's dataformat writers (2026-07-25
 * request): OpenDocument, Excel, CSV and plain text - the same family
 * the gradebook export offers - with the DEFAULT format chosen by the
 * site administrator in the plugin settings. The dataformat writers
 * also emit correct encoding (the hand-rolled CSV was mojibake in
 * Excel).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {
    /** @var string[] Offered formats: dataformat name => lang key. */
    public const FORMATS = [
        'ods' => 'exportods',
        'excel' => 'exportexcel',
        'csv' => 'exportcsv',
        'txt' => 'exporttxt',
    ];

    /**
     * The admin-configured default format.
     *
     * @return string
     */
    public static function default_format(): string {
        $format = (string) get_config('mod_selfselectadvanced', 'exportformat');

        return isset(self::FORMATS[$format]) ? $format : 'excel';
    }

    /**
     * Send rows to the browser in the requested (or default) format
     * and exit.
     *
     * @param string $filename base file name, no extension
     * @param string[] $columns header labels
     * @param array[] $rows data rows (plain value arrays)
     * @param string|null $format ods|excel|csv|txt, null = admin default
     */
    public static function download(string $filename, array $columns, array $rows, ?string $format = null): void {
        $format = $format !== null && isset(self::FORMATS[$format]) ? $format : self::default_format();
        $filename = clean_filename($filename);

        if ($format === 'txt') {
            // Tab-separated plain text (the gradebook's TXT flavour).
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
            echo "\xEF\xBB\xBF" . implode("\t", $columns) . "\n";
            foreach ($rows as $row) {
                echo implode("\t", array_map(static fn($cell) => str_replace(["\t", "\n"], ' ', (string) $cell), $row)) . "\n";
            }
            die;
        }

        $keys = array_keys($columns);
        \core\dataformat::download_data(
            $filename,
            $format,
            array_combine($keys, $columns),
            $rows,
            static function (array $row) use ($keys): array {
                return array_combine($keys, array_pad(array_values($row), count($keys), ''));
            }
        );
        die;
    }

    /**
     * A GET format selector + download button pair for a report page.
     *
     * @param \moodle_url $url the page url carrying the report params
     * @param string $selectedtab value for the 'tab' param, '' = none
     * @return string html
     */
    public static function controls(\moodle_url $url, string $selectedtab = ''): string {
        global $OUTPUT;

        $options = [];
        foreach (self::FORMATS as $format => $key) {
            $options[$format] = get_string($key, 'mod_selfselectadvanced');
        }
        $html = \html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(),
            'class' => 'd-inline-flex gap-2 align-items-center']);
        foreach ($url->params() as $name => $value) {
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        if ($selectedtab !== '') {
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $selectedtab]);
        }
        $html .= \html_writer::label(
            get_string('exportas', 'mod_selfselectadvanced'),
            'ssa-exportformat',
            true,
            ['class' => 'me-1']
        );
        $html .= \html_writer::select(
            $options,
            'download',
            self::default_format(),
            false,
            ['id' => 'ssa-exportformat', 'class' => 'form-select w-auto d-inline-block me-1']
        );
        $html .= \html_writer::empty_tag('input', ['type' => 'submit',
            'value' => get_string('download'), 'class' => 'btn btn-secondary']);
        $html .= \html_writer::end_tag('form');

        return $html;
    }
}
