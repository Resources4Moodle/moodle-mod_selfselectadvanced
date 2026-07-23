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

namespace mod_selfselectadvanced;

use cm_info;
use context_module;
use stdClass;

/**
 * Instance model: the activity record, its course module and context.
 *
 * The raw settings record is exposed only here; rule checks must consume
 * limits through the override resolver, never via settings() directly
 * (architecture plan section 6.3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity {
    /**
     * Constructor.
     *
     * @param stdClass $record row from the selfselectadvanced table
     * @param cm_info $cm course module info
     */
    private function __construct(
        /** @var stdClass Row from the selfselectadvanced table. */
        private readonly stdClass $record,
        /** @var cm_info Course module info. */
        private readonly cm_info $cm,
    ) {
    }

    /**
     * Load from a course module id.
     *
     * @param int $cmid course module id
     * @return self
     */
    public static function from_cmid(int $cmid): self {
        global $DB;

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'selfselectadvanced');
        $record = $DB->get_record('selfselectadvanced', ['id' => $cm->instance], '*', MUST_EXIST);

        return new self($record, $cm);
    }

    /**
     * Load from an instance id.
     *
     * @param int $instanceid selfselectadvanced id
     * @return self
     */
    public static function from_instance(int $instanceid): self {
        global $DB;

        $record = $DB->get_record('selfselectadvanced', ['id' => $instanceid], '*', MUST_EXIST);
        [, $cm] = get_course_and_cm_from_instance($record->id, 'selfselectadvanced', $record->course);

        return new self($record, $cm);
    }

    /**
     * The instance id.
     *
     * @return int
     */
    public function id(): int {
        return (int) $this->record->id;
    }

    /**
     * The course id.
     *
     * @return int
     */
    public function courseid(): int {
        return (int) $this->record->course;
    }

    /**
     * The course module.
     *
     * @return cm_info
     */
    public function cm(): cm_info {
        return $this->cm;
    }

    /**
     * The module context.
     *
     * @return context_module
     */
    public function context(): context_module {
        return context_module::instance($this->cm->id);
    }

    /**
     * The raw settings record.
     *
     * Rule checks must not read limits from here; they go through the
     * override resolver (architecture plan section 6.3).
     *
     * @return stdClass
     */
    public function settings(): stdClass {
        return $this->record;
    }

    /**
     * Formatted activity name.
     *
     * @return string
     */
    public function name(): string {
        return format_string($this->record->name, true, ['context' => $this->context()]);
    }
}
