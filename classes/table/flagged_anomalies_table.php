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

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;

/**
 * The flagged report's students tab: the group anomalies section -
 * leaderless groups (M1), out-of-limit grandfathered groups (4A.8),
 * full-but-guideless groups and groups whose leader is no longer an
 * active participant. Every issue string and the flagged group's
 * confirmed member names (comma-separated, appended to the issues
 * cell) are built by build_rows() from batched queries, so the caller
 * (flagged.php) only ever gets a ready array; this class mirrors
 * flagged_quota_table beyond that: it gives that array a native
 * sortable, paginated look instead of a bare list.
 *
 * Its sort and page GET parameters are remapped away from the
 * defaults so they do not collide with the groupless list's own
 * tsort/tdir/page handling, or with the missing-attributes table
 * above it: all three share the students tab page at once.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flagged_anomalies_table extends \flexible_table {
    /** @var bool Whether the viewer may dissolve a team (decision 6). */
    private bool $candissolve;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url (with active filters)
     * @param bool $candissolve whether the viewer holds :manage AND
     *        :overriderules, and so may dissolve a dead-end team
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl, bool $candissolve = false) {
        parent::__construct($uniqueid);

        $this->candissolve = $candissolve;

        $this->define_columns(['name', 'pluginuid', 'state', 'issues', 'actions']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('flagissues', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        $this->no_sorting('pluginuid');
        $this->no_sorting('issues');
        $this->no_sorting('actions');
        $this->pageable(true);
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-anomalies');
        // Distinct control variable names: this table shares the
        // students tab page with the groupless list's own tsort/tdir/
        // page GET params and with the missing-attributes table, so it
        // must not clash with either.
        $this->set_control_variables([
            TABLE_VAR_SORT => 'anomsort',
            TABLE_VAR_DIR => 'anomdir',
            TABLE_VAR_PAGE => 'anompage',
        ]);
        $this->setup();
    }

    /**
     * Sort (per the current sort preferences), paginate and render an
     * already-filtered array of anomalous groups.
     *
     * @param \stdClass[] $rows entries with name, pluginuid, statelabel, issues, url, dissolveurl
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $sort = $this->get_sort_columns();
        if ($sort) {
            usort($rows, static function ($a, $b) use ($sort) {
                foreach ($sort as $column => $direction) {
                    $key = $column === 'state' ? 'statelabel' : $column;
                    $result = strcasecmp((string) $a->$key, (string) $b->$key);
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
                'name' => \html_writer::link($row->url, $row->name),
                'pluginuid' => $row->pluginuid,
                'state' => $row->statelabel,
                'issues' => $row->issues,
                // A team below minimum with nobody to add is the dead
                // end dissolve exists for; the link goes to its own
                // confirm page, where the reason is typed (D6-3).
                'actions' => $this->candissolve
                    ? \html_writer::link(
                        $row->dissolveurl,
                        get_string('dissolvegroup', 'mod_selfselectadvanced'),
                        ['class' => 'btn btn-outline-danger btn-sm']
                    )
                    : '',
            ]);
        }
        $this->finish_output();
    }

    /**
     * Build one row per anomalous group of the activity: leaderless
     * (M1), out-of-limit grandfathered (4A.8), full-but-guideless
     * (item 5c) and leader-no-longer-active (item 2f) - each row's
     * issues cell also carries its confirmed member names (item 5b),
     * comma-separated.
     *
     * Exactly four queries regardless of activity size, never a query
     * per group or per row: the bulk confirmed/seats-taken counts
     * (groups::count_confirmed_bulk()/count_seats_taken_bulk(), already
     * bulk helpers), one enrolment query for the whole activity's
     * actively-enrolled respond-capability holders, one batched {user}
     * lookup for every distinct leader's deleted/suspended account
     * flags (chunked at 1000), and one batched member+user query for
     * every flagged group's confirmed roster (chunked at 1000,
     * mirroring quota\evaluator::compliance_for_activity()).
     * effective_minsize()/effective_maxsize() stay per-group resolver
     * calls: the shared resolver caches every override row after its
     * first query, so they cost nothing extra here.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver (sole source of effective minsize/maxsize)
     * @return \stdClass[] rows with name, pluginuid, statelabel, issues, url
     */
    public static function build_rows(activity $activity, resolver $resolver): array {
        global $DB;

        $context = $activity->context();
        $allgroups = $DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()]);
        $allgroupids = array_map(static fn($group) => (int) $group->id, $allgroups);
        $confirmedcounts = groups::count_confirmed_bulk($allgroupids);
        $seatstakencounts = groups::count_seats_taken_bulk($allgroupids);
        // Forming and manager-assigns-mode pending_guide (spec A5) are
        // the only states a group can still be legitimately guideless
        // in; beyond those, a full group without a guide cannot recur.
        $liveguidelessstates = [state::FORMING, state::PENDING_GUIDE];

        // Item 2f: one enrolment query for the whole activity, never
        // one per group, to know which users currently hold the
        // respond capability with a live, unsuspended enrolment; and
        // one batched {user} lookup (chunked at 1000) for the
        // account-level deleted/suspended flags of every distinct
        // leader. A leader can fail either test independently:
        // unenrolled/role removed, or the account itself deleted or
        // suspended.
        [$activeenrolledsql, $activeenrolledparams] = get_enrolled_sql(
            $context,
            'mod/selfselectadvanced:respond',
            0,
            true
        );
        $activeenrolledset = array_flip(array_map(
            'intval',
            $DB->get_fieldset_sql("SELECT eu.id FROM ($activeenrolledsql) eu", $activeenrolledparams)
        ));
        $leaderids = array_values(array_unique(array_filter(array_map(
            static fn($group) => (int) $group->leaderid,
            $allgroups
        ))));
        $leaderaccountbad = [];
        foreach (array_chunk($leaderids, 1000) as $leaderchunk) {
            [$leaderinsql, $leaderparams] = $DB->get_in_or_equal($leaderchunk, SQL_PARAMS_NAMED, 'ld');
            $leaderrows = $DB->get_records_sql(
                "SELECT id, deleted, suspended FROM {user} WHERE id $leaderinsql",
                $leaderparams
            );
            foreach ($leaderrows as $leaderrow) {
                $leaderaccountbad[(int) $leaderrow->id] =
                    ((int) $leaderrow->deleted === 1) || ((int) $leaderrow->suspended === 1);
            }
        }

        // Good-neighbour cap audit (RCA Q3): members over their L4
        // membership cap - possible only by grandfathering - flagged
        // proactively on every group that carries one, BEFORE a freeze
        // pushes that roster into the course's groups. Two bulk
        // queries for the whole activity; the resolver serves each
        // per-user cap from its activity-wide override cache.
        $capnamefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $membershipcounts = $DB->get_records_sql(
            "SELECT m.userid, COUNT(1) AS memberships
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} cg ON cg.id = m.groupid
              WHERE cg.activityid = :capactivityid AND m.status = :capconfirmed
           GROUP BY m.userid",
            ['capactivityid' => $activity->id(), 'capconfirmed' => groups::STATUS_CONFIRMED]
        );
        $overcap = [];
        foreach ($membershipcounts as $membershipcount) {
            $cap = $resolver->effective_maxmembership((int) $membershipcount->userid)->value;
            if ((int) $membershipcount->memberships > $cap) {
                $overcap[(int) $membershipcount->userid] = (object) [
                    'current' => (int) $membershipcount->memberships,
                    'max' => $cap,
                ];
            }
        }
        $overcapbygroup = [];
        foreach (array_chunk(array_keys($overcap), 1000) as $overcapchunk) {
            [$ocinsql, $ocparams] = $DB->get_in_or_equal($overcapchunk, SQL_PARAMS_NAMED, 'oc');
            $ocparams['ocactivityid'] = $activity->id();
            $ocparams['occonfirmed'] = groups::STATUS_CONFIRMED;
            $ocrows = $DB->get_records_sql(
                "SELECT m.id, m.groupid, m.userid, $capnamefields
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} cg ON cg.id = m.groupid
                   JOIN {user} u ON u.id = m.userid
                  WHERE cg.activityid = :ocactivityid AND m.status = :occonfirmed
                    AND m.userid $ocinsql",
                $ocparams
            );
            foreach ($ocrows as $ocrow) {
                $overcapbygroup[(int) $ocrow->groupid][] = get_string(
                    'membershipauditmember',
                    'mod_selfselectadvanced',
                    (object) [
                        'fullname' => fullname($ocrow),
                        'current' => $overcap[(int) $ocrow->userid]->current,
                        'max' => $overcap[(int) $ocrow->userid]->max,
                    ]
                );
            }
        }

        // Mirror health (T-16): THREE bulk queries for the whole
        // activity, never one per group - which course groups still
        // exist, who is in them and with what ownership tag, and the
        // confirmed roster of every group. The comparison itself is
        // plain PHP over those maps.
        $coreids = array_values(array_unique(array_filter(array_map(
            static fn($group) => (int) ($group->coregroupid ?? 0),
            $allgroups
        ))));
        $coreexists = [];
        $coremembers = [];
        if ($coreids) {
            foreach (array_chunk($coreids, 1000) as $corechunk) {
                [$coreinsql, $coreparams] = $DB->get_in_or_equal($corechunk, SQL_PARAMS_NAMED, 'cg');
                foreach ($DB->get_fieldset_sql("SELECT id FROM {groups} WHERE id $coreinsql", $coreparams) as $liveid) {
                    $coreexists[(int) $liveid] = true;
                }
                $memberrows = $DB->get_records_sql(
                    "SELECT gm.id, gm.groupid, gm.userid, gm.component
                       FROM {groups_members} gm
                      WHERE gm.groupid $coreinsql",
                    $coreparams
                );
                foreach ($memberrows as $memberrow) {
                    $coremembers[(int) $memberrow->groupid][(int) $memberrow->userid] =
                        (string) $memberrow->component;
                }
            }
        }
        $confirmedmembers = groups::members_confirmed_bulk($allgroupids);

        // First pass: decide which groups are anomalous and why.
        // Member names are deliberately not looked up here - see the
        // batched query below, which runs once for exactly the
        // flagged set instead of once per row.
        $issuesbygroup = [];
        foreach ($allgroups as $group) {
            $groupid = (int) $group->id;
            $issues = [];
            if (empty($group->leaderid)) {
                $issues[] = get_string('flagleaderless', 'mod_selfselectadvanced');
            } else {
                // The group has a leaderid, but that user may since
                // have lost the respond capability (unenrolled,
                // enrolment suspended or expired) or had their account
                // deleted/suspended.
                $leaderid = (int) $group->leaderid;
                $leaderactive = isset($activeenrolledset[$leaderid]) && empty($leaderaccountbad[$leaderid]);
                if (!$leaderactive) {
                    $issues[] = get_string('flagleadergone', 'mod_selfselectadvanced');
                }
            }
            $confirmed = $confirmedcounts[$groupid];
            $seats = $seatstakencounts[$groupid];
            $min = $resolver->effective_minsize($groupid)->value;
            $max = $resolver->effective_maxsize($groupid)->value;
            if ($confirmed < $min || $seats > $max) {
                $issues[] = get_string('flagoutoflimit', 'mod_selfselectadvanced', (object) [
                    'confirmed' => $confirmed,
                    'seats' => $seats,
                    'min' => $min,
                    'max' => $max,
                ]);
            }
            // Full by the confirmed headcount (L1 basis) but still
            // without a guide, and only while the group can still
            // legitimately be guideless.
            if ($confirmed >= $max && empty($group->guideid) && in_array($group->state, $liveguidelessstates, true)) {
                $issues[] = get_string('flagfullnoguide', 'mod_selfselectadvanced');
            }
            // Mirror health, per group, from the maps above.
            $coregroupid = (int) ($group->coregroupid ?? 0);
            if ($group->state === state::FROZEN && (!$coregroupid || !isset($coreexists[$coregroupid]))) {
                // Catches the restore hole too: a restored group can
                // arrive frozen with no coregroupid, and until now
                // nothing said so anywhere.
                $issues[] = get_string('coregroupmissing', 'mod_selfselectadvanced');
            } else if ($coregroupid && isset($coreexists[$coregroupid])) {
                $expected = $confirmedmembers[$groupid] ?? [];
                if (!empty($group->guideid) && !in_array((int) $group->guideid, $expected, true)) {
                    $expected[] = (int) $group->guideid;
                }
                // The report judges ownership by the component tag
                // alone. A resync additionally reclaims legacy untagged
                // rows of people who still hold a plugin member row, so
                // it can remove slightly more than this counts - the
                // link on the row goes to the page where that button is.
                $live = $coremembers[$coregroupid] ?? [];
                $missing = array_values(array_diff($expected, array_keys($live)));
                $strangers = 0;
                foreach ($live as $liveuserid => $component) {
                    if (in_array((int) $liveuserid, $expected, true)) {
                        continue;
                    }
                    if ($component !== \mod_selfselectadvanced\local\freeze::COMPONENT) {
                        $strangers++;
                    }
                }
                if ($missing) {
                    $issues[] = get_string('coregroupincomplete', 'mod_selfselectadvanced', count($missing));
                }
                if ($strangers) {
                    $issues[] = get_string('coregroupstranger', 'mod_selfselectadvanced', $strangers);
                }
            }
            if (isset($overcapbygroup[$groupid])) {
                // A frozen group is already pushed and grandfathered -
                // "cannot be frozen" would be wrong there; the audit
                // bites again only at the next push after unfreezing.
                $issues[] = get_string(
                    $group->state === state::FROZEN ? 'flagovercapfrozen' : 'flagovercap',
                    'mod_selfselectadvanced',
                    implode(', ', $overcapbygroup[$groupid])
                );
            }
            if ($issues) {
                $issuesbygroup[$groupid] = $issues;
            }
        }

        // Item 5b: confirmed member names for every flagged row, one
        // query joining member and user for the whole flagged set
        // (chunked at 1000 ids), never a query per anomaly row.
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $membernamesbygroup = [];
        foreach (array_chunk(array_keys($issuesbygroup), 1000) as $flaggedchunk) {
            [$flaggedinsql, $flaggedparams] = $DB->get_in_or_equal($flaggedchunk, SQL_PARAMS_NAMED, 'fg');
            $flaggedparams['confirmed'] = groups::STATUS_CONFIRMED;
            $memberrows = $DB->get_records_sql(
                "SELECT m.id, m.groupid, $namefields
                   FROM {selfselectadvanced_member} m
                   JOIN {user} u ON u.id = m.userid
                  WHERE m.groupid $flaggedinsql AND m.status = :confirmed
               ORDER BY m.groupid, u.lastname, u.firstname",
                $flaggedparams
            );
            foreach ($memberrows as $memberrow) {
                $membernamesbygroup[(int) $memberrow->groupid][] = fullname($memberrow);
            }
        }

        // Second pass: assemble the rows now that both the issue
        // strings and the member names are known for the flagged set.
        $rows = [];
        foreach ($allgroups as $group) {
            $groupid = (int) $group->id;
            if (!isset($issuesbygroup[$groupid])) {
                continue;
            }
            $issuestext = implode(' ', $issuesbygroup[$groupid]);
            if (!empty($membernamesbygroup[$groupid])) {
                $issuestext .= ' ' . \html_writer::span(
                    implode(', ', $membernamesbygroup[$groupid]),
                    'selfselectadvanced-anomalymembers text-muted'
                );
            }
            $rows[] = (object) [
                'name' => format_string($group->name),
                'pluginuid' => $group->pluginuid,
                'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
                'issues' => $issuestext,
                'url' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => $groupid,
                ]))->out(false),
                // Built from the group id already in the row: no extra
                // query, display only.
                'dissolveurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => $groupid,
                    'action' => 'dissolve',
                ]))->out(false),
            ];
        }

        return $rows;
    }
}
