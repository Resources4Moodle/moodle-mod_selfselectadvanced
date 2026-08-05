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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\guideload_table;
use renderable;
use renderer_base;
use templatable;

/**
 * The guide's own decision window, rendered inline at the top of the
 * activity landing (1.20.6 item A).
 *
 * The maintainer's finding: a non-editing teacher - the guide role -
 * landing on the activity got a student-shaped page. A student-addressed
 * approach notice, a "Joining another team" button that ended at a
 * permission exception, one small "Guide dashboard" link near the
 * bottom, and on upgraded sites a table of everybody ELSE's teams. The
 * work they are actually here to do - the teams waiting on their
 * decision, and how long they have to make it - appeared nowhere.
 *
 * Every number here comes from a seam that already exists and is
 * already exercised elsewhere, so the landing and the dashboard cannot
 * quote different figures for the same thing:
 *
 * - the pending teams, their deadlines and their overdue flags come
 *   from guideload_table::export_rows(), the same dataset (and the same
 *   deadline arithmetic) the guide-load drill-down builds its table
 *   from; this class never recomputes timesubmitted + guidewindow;
 * - the load line is eoi::guide_commitments() over
 *   resolver::effective_maxguided(), which is the identical pair of
 *   calls guide.php makes for its own load line, fed to the identical
 *   string;
 * - the approach queue count is contacts::waiting_for(), which is what
 *   guide.php counts for its queue button.
 *
 * THE PANEL NEVER RENDERS EMPTY. guidewindow exists only as a DERIVED
 * per-team deadline - timesubmitted + guidewindow - so in a brand-new
 * activity, before any team has submitted anything, there is no
 * deadline in existence to draw. Showing nothing there would answer the
 * maintainer's finding with a blank box, so the panel states the POLICY
 * unconditionally ("Once a team submits to you, you have N to approve
 * or return it") and adds the derived per-team lines only once teams
 * have actually submitted.
 *
 * NO PARTICIPANT FIELD IS READ HERE, for any viewer. Every value the
 * panel prints is a team name, a plugin uid, a state, a date or a
 * count - the same guarantee the seam it reuses already states - so the
 * contact-privacy switch has nothing to act on in this panel and no
 * connection is a special case.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guide_panel implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param api $api the application facade
     * @param int $userid the guide whose work this is
     */
    public function __construct(
        /** @var api The application facade. */
        private readonly api $api,
        /** @var int The guide whose work this is. */
        private readonly int $userid,
    ) {
    }

    /**
     * Export for the guide panel template.
     *
     * @param renderer_base $output the renderer
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $activity = $this->api->activity();
        $settings = $activity->settings();
        $cmid = $activity->cm()->id;
        $guidewindow = (int) $settings->guidewindow;

        // ONE fetch, the same dataset guideload.php exports for the
        // same guide with the same window, so the panel's deadline
        // arithmetic and the drill-down's are the SAME arithmetic -
        // guideload_table::deadline_info(), not a second copy of
        // timesubmitted + guidewindow living here.
        $rows = guideload_table::export_rows($activity, $this->userid, $guidewindow);

        $awaiting = [];
        $overdue = 0;
        $next = 0;
        foreach ($rows as $row) {
            // A firm, frozen or forming team is LOAD - it counts
            // against the cap - but it is not a decision waiting on
            // this guide, and this list is the decisions.
            if ($row->state !== state::PENDING_GUIDE) {
                continue;
            }
            $awaiting[] = (object) [
                'name' => format_string($row->rawname),
                'pluginuid' => $row->pluginuid,
                'hasdeadline' => $row->deadline > 0,
                'deadline' => $row->deadline ? userdate($row->deadline) : '',
                'isoverdue' => $row->overdue,
            ];
            if ($row->overdue) {
                $overdue++;
                continue;
            }
            // A deadline that has already passed is reported by the
            // overdue line; "next" is the next one still to come, so
            // an overdue team never becomes the answer to "what is due
            // next?".
            if ($row->deadline > 0 && ($next === 0 || $row->deadline < $next)) {
                $next = $row->deadline;
            }
        }

        $resolver = $this->api->gatekeeper()->resolver();
        $used = eoi::guide_commitments($activity, $this->userid);
        $max = $resolver->effective_maxguided($this->userid)->value;
        $waiting = count(contacts::waiting_for($activity, $this->userid));

        return (object) [
            'heading' => get_string('guidepanelheading', 'mod_selfselectadvanced'),
            'loadline' => get_string('guideloadheader', 'mod_selfselectadvanced', (object) [
                'used' => $used,
                'max' => $max,
            ]),
            // Raw, so a test can assert the figures rather than the prose.
            'used' => $used,
            'max' => $max,
            'awaitingcount' => count($awaiting),
            'hasawaiting' => (bool) $awaiting,
            'awaitingline' => get_string('guidepanelawaiting', 'mod_selfselectadvanced', count($awaiting)),
            'nothingline' => get_string('guidepanelnothingwaiting', 'mod_selfselectadvanced'),
            'overduecount' => $overdue,
            'hasoverdue' => $overdue > 0,
            'overdueline' => get_string('guidepaneloverdue', 'mod_selfselectadvanced', $overdue),
            'nextdeadline' => $next,
            'hasnextdeadline' => $next > 0,
            'nextdeadlineline' => $next > 0
                ? get_string('guidepanelnextdeadline', 'mod_selfselectadvanced', userdate($next))
                : '',
            'windowpolicy' => $this->window_policy($guidewindow, !empty($settings->guideautoapprove)),
            'waitingcount' => $waiting,
            'haswaiting' => $waiting > 0,
            'waitingline' => get_string('contactreviewwaiting', 'mod_selfselectadvanced', $waiting),
            'queuelabel' => get_string('guiderequestqueue', 'mod_selfselectadvanced'),
            'awaiting' => $awaiting,
            // Both pages require exactly mod/selfselectadvanced:guide,
            // which is the capability that caused this panel to be
            // rendered at all, so neither link can land on a refusal.
            'queueurl' => (new \moodle_url('/mod/selfselectadvanced/guidequeue.php', ['id' => $cmid]))->out(false),
            'dashboardurl' => (new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cmid]))->out(false),
        ];
    }

    /**
     * The decision rule, stated as a rule rather than as a date.
     *
     * This is the line that makes the panel safe to render in a
     * brand-new activity: it depends only on the SETTINGS, never on a
     * submitted team, so it is there before the first submission and
     * stays there afterwards alongside the derived deadlines.
     *
     * @param int $guidewindow the decision window in seconds, 0 = none
     * @param bool $autoapprove whether an undecided team firms itself when the window lapses
     * @return string
     */
    private function window_policy(int $guidewindow, bool $autoapprove): string {
        if ($guidewindow <= 0) {
            return get_string('guidepanelwindownone', 'mod_selfselectadvanced');
        }

        return get_string(
            $autoapprove ? 'guidepanelwindowauto' : 'guidepanelwindow',
            'mod_selfselectadvanced',
            format_time($guidewindow)
        );
    }
}
