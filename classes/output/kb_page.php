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

namespace mod_selfselectadvanced\output;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\tickets;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The knowledgebank's list screen (1.20.45), kb.php's default view: the
 * STAFF list (every entry, published or not, with edit/unpublish or
 * republish/delete) for queue authority, or the STUDENT browse+search
 * view (published only, grouped by type) for anyone else who can view
 * the activity - kb.php itself decides which this viewer gets.
 *
 * Every published row a student sees goes through kb::export_entry()
 * (the single serialiser, class docblock on kb.php) for its
 * already-rendered 'answerhtml' - this page never calls format_text()
 * on a kb row itself.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class kb_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param bool $isstaff whether the viewer holds queue authority
     * @param string $q the student view's search term, '' for none
     */
    public function __construct(
        /** @var activity The activity. */
        private readonly activity $activity,
        /** @var bool Whether the viewer holds queue authority. */
        private readonly bool $isstaff,
        /** @var string The student view's search term. */
        private readonly string $q = '',
    ) {
    }

    /**
     * Export for the list template.
     *
     * @param renderer_base $output the renderer
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $cmid = $this->activity->cm()->id;

        $data = [
            'cmid' => $cmid,
            'isstaff' => $this->isstaff,
            'sesskey' => sesskey(),
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/kb.php', ['id' => $cmid]))->out(false),
            'backurl' => (new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cmid]))->out(false),
            'backlabel' => get_string('back'),
        ];
        $data += (array) ($this->isstaff ? $this->export_staff_list() : $this->export_student_view());

        return (object) $data;
    }

    /**
     * The staff list: every entry, published or not.
     *
     * @return stdClass
     */
    private function export_staff_list(): stdClass {
        $cmid = $this->activity->cm()->id;
        $rows = kb::search($this->activity, '', '', false);

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = (object) [
                'id' => (int) $row->id,
                'title' => format_string($row->title),
                'typelabel' => $this->type_label($row->tickettype),
                'published' => (int) $row->published === 1,
                'statuslabel' => (int) $row->published === 1
                    ? get_string('kbstatuspublished', 'mod_selfselectadvanced')
                    : get_string('kbstatusunpublished', 'mod_selfselectadvanced'),
                'editurl' => (new \moodle_url(
                    '/mod/selfselectadvanced/kb.php',
                    ['id' => $cmid, 'action' => 'form', 'e' => $row->id]
                ))->out(false),
            ];
        }

        return (object) [
            'staffheading' => get_string('kbstaffheading', 'mod_selfselectadvanced'),
            'addurl' => (new \moodle_url('/mod/selfselectadvanced/kb.php', ['id' => $cmid, 'action' => 'form']))->out(false),
            'addlabel' => get_string('kbaddarticle', 'mod_selfselectadvanced'),
            'entries' => $entries,
            'hasentries' => !empty($entries),
            'noarticles' => get_string('kbnoarticles', 'mod_selfselectadvanced'),
        ];
    }

    /**
     * The student view: published entries only, grouped by type, with
     * an optional search term already applied.
     *
     * @return stdClass
     */
    private function export_student_view(): stdClass {
        $cmid = $this->activity->cm()->id;
        $groups = [];
        foreach (array_merge([''], tickets::known_types()) as $type) {
            $rows = kb::search($this->activity, $type, $this->q, true);
            if (!$rows) {
                continue;
            }
            $entries = [];
            foreach ($rows as $row) {
                $exported = kb::export_entry($row);
                $entries[] = (object) [
                    'title' => $exported['title'],
                    'answerhtml' => $exported['answerhtml'],
                ];
            }
            $groups[] = (object) [
                'typelabel' => $this->type_label($type),
                'entries' => $entries,
            ];
        }

        return (object) [
            'studentheading' => get_string('kbstudentheading', 'mod_selfselectadvanced'),
            'searchurl' => (new \moodle_url('/mod/selfselectadvanced/kb.php', ['id' => $cmid]))->out(false),
            'searchlabel' => get_string('kbsearchlabel', 'mod_selfselectadvanced'),
            'searchplaceholder' => get_string('kbsearchplaceholder', 'mod_selfselectadvanced'),
            'searchbutton' => get_string('kbsearchbutton', 'mod_selfselectadvanced'),
            'q' => $this->q,
            'groups' => $groups,
            'hasgroups' => !empty($groups),
            'nopublished' => get_string('kbnopublished', 'mod_selfselectadvanced'),
        ];
    }

    /**
     * A tickettype value's display label - the general ('') group's own
     * label for the empty string, tickets' own type label otherwise.
     *
     * @param string $type tickets::TYPE_*, or ''
     * @return string
     */
    private function type_label(string $type): string {
        return $type !== ''
            ? get_string('tickettype' . $type, 'mod_selfselectadvanced')
            : get_string('kbtypegeneral', 'mod_selfselectadvanced');
    }
}
