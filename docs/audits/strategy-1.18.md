# 1.18.0 — work order of 2026-07-30 (second)

Seven items after the maintainer worked the 1.17.0 interface on the live
site. The theme running through all of them is the same: **a page that
is merely correct at ten teams is unusable at fifteen hundred**, and a
control that lists everything is the commonest way to fail that test.

## A. The gate must fail on what GitHub fails on

The premise of the item was that the box ran an older moodle-cs. It did
not: box and GitHub both resolve `moodlehq/moodle-cs v3.7.0`, the
newest tagged, with identical `php_codesniffer`, `phpcsextra` and
`phpcsutils`. Rebuilding the toolchain the way the workflow does
(`composer create-project ... ^4`) produced a byte-identical lock.

The real gap was **flags and coverage**:

- `phpcs` and `phpdoc` ran without `--max-warnings 0`, so every finding
  that moodle-cs classifies as a warning passed locally and failed on
  GitHub. That is exactly what happened in 1.17.0: an undocumented
  `@param` and a lowercase comment.
- `phplint`, `mustache` and `grunt` did not run locally at all.
- `phpunit` ran without `--fail-on-warning`.

All seven checks now run with the workflow's flags. `mustache` and
`grunt` run against the copy inside the Moodle tree, which is the only
place they work — and for `grunt` the only way to catch an `amd/build`
output that no longer matches `amd/src`.

## B. No control may list every guide

At 1500 guides a `<select>` is not a control, it is a wall — and on the
assignment queue there is one per row. Every guide picker becomes a
**searchable autocomplete** backed by a new
`mod_selfselectadvanced_search_guides` web service, following the
pattern the invitation candidate picker has used since 1.5: the element
ships with **no options at all** and fetches only what a query matches,
capped server-side.

The pickers: the assignment queue (both tabs), the team's submit-to-a-
guide control, the approach-a-guide chooser, and the handover nominee.
Each result carries the guide's load and department, so the choice is
informed without a second page.

## C. A guide's requests are a queue, not a scattering

Everything waiting for a guide to answer — team approaches, incoming
handover proposals — lived in three different places on one long page,
or behind a link. They become one **request queue**, a paged, filtered,
downloadable table on a page of its own.

Added to it, the item the order asks for: a guide may **request a
higher team limit** from the coordinators. This is a ticket of a new
type, `guidecap`, which unlike the other two is not about a team, so
the ticket's group becomes optional and the request carries the number
asked for. A coordinator working the queue can **grant it in one
action**, which writes the guide-capacity override and resolves the
ticket together, or decline it with a reason.

## D. Appointing coordinators the way Moodle appoints roles

The bulk upload stays, but it is no longer the only way in. The page
gains a **participants-style table** — the idiom of Moodle's own role
screens — listing course participants with their roles, filtered to
non-editing teachers by default, paged, sortable, filterable by name
and downloadable. Each row carries the one action it needs: appoint, or
remove. One or two people no longer require a spreadsheet.

Sample **CSV and XLSX** templates are downloadable from the page, so
nobody has to guess the column names.

## E. Tabs, wherever a table sits under a table

The order states the rule generally, so it is applied generally rather
than only where it was noticed:

- `manage.php` put the assignment tabs *below* the full team table, so
  the tab row itself was under the fold. The page becomes **one** tab
  row: Teams, Awaiting a guide, Change a guide, Guide loads.
- `guide.php` stacked the approach link, the digest form, the handover
  block and two dashboard tables. It becomes tabs.
- Every other page carrying more than one table is audited and tabbed
  the same way.

## F. Guide loads is a report, not a list

Paging and sorting arrived in 1.17; filters and download did not. It
gains a name filter, a has-room filter, and the standard Moodle
download selector.

## G. Re-shoot everything

Table layouts have moved on most screens, so the whole deck is re-shot
rather than patched — which, as the order notes, is also the fastest
way to surface a page that has quietly broken.

## What the round found

**The premise of item A was wrong, and worth recording as such.** The
box was not running an older moodle-cs — it was running the same
`v3.7.0` GitHub resolves, with an identical lock down to
`php_codesniffer`. What made GitHub stricter was `--max-warnings 0`,
which the local gate omitted on both `phpcs` and `phpdoc`, so every
finding classified as a warning passed locally and failed remotely.
That is precisely the 1.17.0 failure — an undocumented `@param` and a
lowercase comment — and it had been mis-diagnosed as a version gap ever
since. Three checks (`phplint`, `mustache`, `grunt`) were not running
locally at all.

The lesson generalises: **when a local gate and a remote one disagree,
compare the commands before comparing the versions.**

**A test caught a defect in a page, not in itself.** The new coordinator
test tripped `Unexpected debugging() call detected` from
`get_role_users($roleid, $context, false, 'u.id')` — core adds the name
fields the sort needs and says so. The same call was in
`coordinators.php`, so every load of that page would have emitted it on
a site with developer debugging on. Fixed in both.

**Core refuses whitespace before the function sees it.**
`PARAM_RAW_TRIMMED` rejects `'   '` in `validate_parameters()`, so the
service's own empty-query guard can only ever be reached by a genuinely
empty string. The test now asserts both halves, so that a later
loosening of the parameter type cannot quietly turn a stray space into
a request for every guide in the school.

**Granting an exception is a different authority from working the
queue.** The grant path writes an override, so it is gated on
`:override` at the seam rather than on the page — a site that gives the
coordinator role the queue but not the exception-setting power gets the
behaviour it asked for.

**The searchable picker leaked what students-approach mode exists to
hide.** The new label carried the guide's load — "Guiding 2 of 3" —
into every picker, including the chooser a team uses in
students-approach mode, where advertised availability is precisely what
1.16 A removed. The plugin's own Behat suite caught it, on a scenario
written two releases earlier for exactly this property.

The fix belongs where the picker is fed rather than in each page: the
service decides on capability, so staff assigning work and a guide
nominating a successor keep the figure, and the teams choosing do not.
It is now pinned by unit tests on both sides of the rule.

The general lesson: **a control that replaces several older ones
inherits every rule each of them carried.** Four pickers were merged
into one service; three of the four had no such rule, and the fourth's
was invisible until a test failed.

## A change worth stating plainly

The guide pickers now **require JavaScript**, as Moodle's own user
selectors do — including the invitation picker this plugin has used
since 1.5. Without it, the control renders as an empty select. That is
the trade for a school with 1500 guides: the alternative is the list
that made the page unusable in the first place. The two Behat scenarios
that exercise a chooser are tagged `@javascript` accordingly.

## The rule was not applied widely enough the first time

1.18.0 searched every GUIDE picker and left the TEAM pickers listing.
The move form held two selects over every team, and the overrides form
an autocomplete that filtered in the browser — which still renders each
option before it can hide one, so at user scope it was building all ten
thousand enrolled students into a page.

The lesson is the one this round keeps teaching, one level up: **when a
rule is adopted, sweep for every control it applies to, not just the
one that prompted it.** "No control lists everything" was written about
guides and applied to guides. Teams, and students, went on listing.

Team search matches the name AND the project id, because staff work
from whichever of the two they have in front of them, and the query
lives in `groups::search()` rather than in the web service — so the
scale harness can measure a keystroke without needing a session.
