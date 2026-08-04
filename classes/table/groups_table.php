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
 * The manager dashboard groups listing (spec 14.13, 4A.6, C12): core
 * table_sql with native sort, paging, state filter and download.
 * The Size column shows confirmed+pending against min-max.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groups_table extends \table_sql {
    /** @var \mod_selfselectadvanced\activity The activity. */
    private $activity;

    /** @var \mod_selfselectadvanced\local\rules\gatekeeper The gatekeeper. */
    private $gatekeeper;

    /**
     * @var int The viewer the action column is built for.
     *
     * Passed in rather than read from $USER, for the reason
     * \local\authority states in full: an authority question takes its
     * actor explicitly, so a test can ask it about somebody who is not
     * the current user and a caller cannot accidentally ask it about
     * the wrong person.
     */
    private $userid;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \mod_selfselectadvanced\activity $activity the activity
     * @param \mod_selfselectadvanced\local\rules\gatekeeper $gatekeeper for seat positions
     * @param \moodle_url $baseurl page url
     * @param string $statefilter '' or a state name
     * @param bool $download whether a download is in progress
     * @param int $userid the viewer whose actions the table offers
     */
    public function __construct(
        string $uniqueid,
        \mod_selfselectadvanced\activity $activity,
        \mod_selfselectadvanced\local\rules\gatekeeper $gatekeeper,
        \moodle_url $baseurl,
        string $statefilter,
        bool $download,
        int $userid
    ) {
        parent::__construct($uniqueid);
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
        $this->userid = $userid;

        $columns = ['name', 'pluginuid', 'state', 'leadername', 'guidename', 'size', 'penaltyvalue'];
        $headers = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('leader', 'mod_selfselectadvanced'),
            get_string('guidelabelplain', 'mod_selfselectadvanced'),
            get_string('size', 'mod_selfselectadvanced'),
            get_string('ledgerpenalty', 'mod_selfselectadvanced'),
        ];
        if (!$download) {
            $columns[] = 'actions';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        $this->no_sorting('size');
        $this->no_sorting('actions');
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $where = 'g.activityid = :activityid';
        $params = ['activityid' => $activity->id()];
        if ($statefilter !== '') {
            $where .= ' AND g.state = :statefilter';
            $params['statefilter'] = $statefilter;
        }
        // Every name field is selected and aliased, because fullname()
        // needs the complete set to honour the site's name format.
        $namefields = \core_user\fields::get_name_fields();
        $leaderfields = implode(', ', array_map(
            static fn(string $f) => "l.$f AS leader$f",
            $namefields
        ));
        $guidefields = implode(', ', array_map(
            static fn(string $f) => "gu.$f AS guide$f",
            $namefields
        ));
        // Seat counts ride along as aggregates (RCA-1, 10k probe):
        // without them col_size costs two COUNT queries per rendered
        // row, and the export walks the whole activity. The derived
        // table is scoped to THIS activity (its own named param - the
        // outer one may not repeat), because the planner cannot push
        // the join qualifier through the GROUP BY, and an unscoped
        // aggregate would scan every activity's members on every page.
        $params['mcactivityid'] = $activity->id();
        // The guidesuccessorid column rides along for col_actions: the
        // conflict-of-interest guard behind freeze::may_freeze_team()
        // reads it, and a row that arrived without it would answer
        // "not involved" for the nominated successor guide and offer
        // them a Freeze the service then refuses. A column absent from
        // a SELECT is not a null, it is a missing answer.
        //
        // frozenbystaff rides along for the same reason on the other
        // direction (UX-001): freeze::may_unfreeze_team() reads it to
        // tell a guide's own freeze from one an editing teacher or a
        // coordinator enforced. Its absence would read as "no member of
        // staff froze this", which is the permissive answer - and
        // release_refusal() now REFUSES a partial row rather than
        // judging one, so a future SELECT that drops either column
        // fails loudly instead of quietly widening the offer.
        $this->set_sql(
            "g.id, g.name, g.pluginuid, g.state, g.leaderid, g.guideid, g.guidesuccessorid,
             g.frozenbystaff, p.penaltyvalue,
             COALESCE(mc.confirmedcount, 0) AS confirmedcount,
             COALESCE(mc.invitedcount, 0) AS invitedcount,
             $leaderfields, $guidefields",
            "{selfselectadvanced_group} g
             JOIN {user} l ON l.id = g.leaderid
             LEFT JOIN {user} gu ON gu.id = g.guideid
             LEFT JOIN {selfselectadvanced_penalty} p ON p.groupid = g.id
             LEFT JOIN (
                 SELECT m.groupid,
                        SUM(CASE WHEN m.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmedcount,
                        SUM(CASE WHEN m.status = 'invited' THEN 1 ELSE 0 END) AS invitedcount
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} mg ON mg.id = m.groupid
                  WHERE mg.activityid = :mcactivityid
               GROUP BY m.groupid
             ) mc ON mc.groupid = g.id",
            $where,
            $params
        );
    }

    /**
     * Group name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_name($row) {
        return format_string($row->name);
    }

    /**
     * Localised state.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_state($row) {
        return get_string('state' . str_replace('_', '', $row->state), 'mod_selfselectadvanced');
    }

    /**
     * Leader name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_leadername($row) {
        return fullname(self::name_object($row, 'leader'));
    }

    /**
     * Guide name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_guidename($row) {
        return $row->guideid ? fullname(self::name_object($row, 'guide')) : '';
    }

    /**
     * Size against the effective band (4A.6).
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_size($row) {
        $seats = $this->gatekeeper->seat_position(
            $row,
            isset($row->confirmedcount) ? (int) $row->confirmedcount : null,
            isset($row->invitedcount) ? (int) $row->invitedcount : null
        );
        $key = (int) $seats->min === (int) $seats->max ? 'sizecellexact' : 'sizecellrange';
        $cell = get_string($key, 'mod_selfselectadvanced', $seats);
        if ($seats->invited > 0) {
            $cell = get_string('sizecellinvited', 'mod_selfselectadvanced', (object) [
                'core' => $cell,
                'invited' => $seats->invited,
            ]);
        }

        return $cell;
    }

    /**
     * Penalty value.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_penaltyvalue($row) {
        return $row->penaltyvalue === null ? '' : format_float((float) $row->penaltyvalue, 2);
    }

    /**
     * View, freeze and unfreeze actions.
     *
     * FREEZE WAS MISSING UNTIL THIS WAVE (ACT-003). This table is the
     * team list on BOTH staff dashboards - manage.php and, since 1.17,
     * coordinator.php - and freeze::freeze_group() has admitted a
     * :manage or :coordinate holder on its on-behalf branch since
     * strategy 1.16 D. The capability existed, the service accepted it,
     * the Group Coordinator role was granted it at install, the
     * coordinator dashboard even counted the teams awaiting a freeze on
     * one of its four cards - and there was no control anywhere that
     * performed it. Unfreeze was offered here; freeze never was.
     *
     * The offer is the service's own predicate, so a link that appears
     * is a link that works: may_freeze_team() is freeze_group()'s gate,
     * and the conflict-of-interest guard inside it is what keeps the
     * link off the rows a coordinator is involved in. State is asked
     * separately and deliberately - only FIRM teams can be frozen, and
     * that is rules\gatekeeper's question, not the actor's.
     *
     * The column is not built for a DOWNLOAD (see the constructor), so
     * the per-row cost is bounded by the page size: no query at all for
     * a :manage holder, at most one indexed membership read otherwise.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_actions($row) {
        $out = \html_writer::link(
            new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $this->activity->cm()->id,
                'g' => $row->id,
            ]),
            get_string('view'),
            ['class' => 'btn btn-secondary btn-sm']
        );
        if (
            $row->state === \mod_selfselectadvanced\local\state::FIRM
            && \mod_selfselectadvanced\local\freeze::may_freeze_team($this->activity, $row, $this->userid)
        ) {
            $out .= ' ' . \html_writer::link(
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $this->activity->cm()->id,
                    'g' => $row->id,
                    'action' => 'freeze',
                ]),
                get_string('freeze', 'mod_selfselectadvanced'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        // AND UNFREEZE WAS OFFERED TO EVERYONE (UX-001). This branch
        // asked the STATE and nothing else: every frozen row on the
        // coordinator dashboard carried a Release link, including the
        // coordinator's own team, which unfreeze() refuses on conflict
        // of interest - and including every row on a site that has
        // withdrawn :unfreeze from a :manage holder, whom the service
        // refuses for want of the capability. Same predicate, same
        // rule: the link that appears is the link that works.
        if (
            $row->state === \mod_selfselectadvanced\local\state::FROZEN
            && \mod_selfselectadvanced\local\freeze::may_unfreeze_team($this->activity, $row, $this->userid)
        ) {
            $out .= ' ' . \html_writer::link(
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $this->activity->cm()->id,
                    'g' => $row->id,
                    'action' => 'unfreeze',
                ]),
                get_string('unfreeze', 'mod_selfselectadvanced'),
                ['class' => 'btn btn-warning btn-sm']
            );
        }

        return $out;
    }

    /**
     * Rebuild a user-shaped object from the aliased name columns so
     * fullname() sees every field it expects.
     *
     * @param \stdClass $row the fetched row
     * @param string $prefix column alias prefix, leader or guide
     * @return \stdClass
     */
    private static function name_object(\stdClass $row, string $prefix): \stdClass {
        $user = new \stdClass();
        foreach (\core_user\fields::get_name_fields() as $field) {
            $alias = $prefix . $field;
            $user->$field = $row->$alias ?? '';
        }

        return $user;
    }
}
