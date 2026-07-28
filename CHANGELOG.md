# Changelog

## 1.15.1 (2026-07-28)

- The number at the end of a group id has a configurable width: the
  new "Group id digits" setting takes 2 to 10, so ids read
  MDP-COURSE-042 or MDP-COURSE-000042 as the school prefers. A number
  too large for the width keeps all its digits rather than being cut
  short, and like the prefix the setting stamps groups created
  afterwards only.
- A course whose short name carries no letters or digits now names its
  group ids from the course full name instead of falling straight to
  the course number.


## 1.15.0 (2026-07-28)

- Frozen teams stay true in Moodle's own groups: the mirror already
  followed every manager move, freeze and unfreeze; now a deleted user
  account also leaves every roster immediately, with frozen teams
  re-snapshotted so an unfreeze or repair can never resurrect the
  ghost. Out-of-band edits to the core group are still reported as
  drift, never overwritten.
- Good-neighbour membership audit: freezing is the moment a team is
  pushed into the course's groups and groupings, so a roster carrying
  a member over their membership cap (possible only when caps were
  lowered after people joined) is refused at that gate, every manager
  is notified with the member names and counts, and the flagged
  report shows the condition proactively. The plugin never raises a
  cap by itself - that decision stays with the manager, via the
  activity setting or a per-user override.
- The group id prefix (the SSA in SSA-COURSE-0042) is now an activity
  setting: 2-8 letters or digits, upper-cased, applied to groups
  created after the change; existing ids are never rewritten. The
  mirrored core group's name prefix remains the module ID number (or
  activity name) from the common settings.


## 1.14.1 (2026-07-28)

- Measured at 10,000 students on the maintainer testbed
  (docs/tools/scale_scenarios.php, findings in
  docs/audits/rca-scale-10k.md): the manager team table now preloads
  its seat counts (a 1,900-row export drops from ~3,800 queries to 3),
  and guide loads batch their volunteering and commitment lookups
  (200 guides drop from ~600 queries to 5). Same numbers, same
  precedence, pinned by equivalence tests; every capacity gate keeps
  its per-guide locked path.


## 1.14.0 (2026-07-27)

- Guide handover: an assigned guide leaves a submitted, firm or frozen
  team only by nominating another guide with free capacity, and only
  that guide's acceptance — capacity re-checked at that moment —
  completes the exit. Proposals can be declined or cancelled; the
  leader and both guides are notified; a team is never left guideless.
- Managers can reassign the guide of any submitted, firm or frozen
  team from the dashboard's new reassign section; the change is logged,
  everyone affected is told, and any pending handover is superseded.
- Mobile-number consent: each student decides on their landing page
  whether leaders and teammates may see their number. Staff with full
  view always see it; every other surface — rosters, the guide's
  member drill-down, WhatsApp links, exports, the CSV round-trip —
  honours the choice, and a caution notice reminds viewers numbers are
  shared in confidence.
- Guide waitlists: an optional per-team cap on open guide interests;
  guides see their first-come-first-served queue position (only
  "first in line" or "queued" when sequential reveal is on) and their
  remaining picking bandwidth right where they pick.
- Moving people is no longer expert-only: staging errors return to the
  form with the reason instead of a fatal page, a blank source is
  inferred or refused (never a silent second membership), staged moves
  can be edited and restaged, red rows start unticked and the set
  error names the first offender, cancel sits in the row, rosters and
  reports link straight into a prefilled move form, and the toolbar
  says "Move students".
- New reports: full teams without a guide and teams whose leader is no
  longer an active participant are flagged; under-capacity flags list
  the member names; a new group grid shows one row per team — guide
  first, then each member's last name in its own column — filterable
  and exportable.

## 1.13.0 (2026-07-27)

- Leaving a forming group is always possible: the minimum size gates
  submission, not membership, so a team at the minimum can shrink to
  repair its composition.
- Admission feasibility gate: inviting or accepting a member who would
  make the seat plan unreachable within the maximum group size — or who
  breaks a counting-rule maximum, which adding members can never repair
  — is refused with the reason, so a team can no longer fill itself
  into a dead end. Quota-exempt overrides bypass the gate.
- Submit to guide is always visible to the leader of a forming team:
  the button disables with the blocking reason beside it and enables
  when the team complies; a declined invitation stays visible on the
  leader's page instead of vanishing.
- Membership capacity is now honoured on every path: creating a group,
  being auto-grouped or being moved by a manager auto-declines the
  student's rival pending invitations with a message to each inviting
  leader (previously only accepting an invitation did); staged-move
  sets validate the membership cap jointly across the whole set.
- Guide capacity is race-proof: submitting and assigning serialise per
  guide (the same lock the pick-that-team flow uses), re-assigning the
  guide a team already has no longer falsely refuses at capacity, and
  the decision-window sweep no longer auto-approves teams that have no
  guide to stand in for — they stay in the manager queue.
- Manager move verdicts now evaluate the seat plan, not only counting
  rules; a move whose successor is no longer a confirmed member of the
  source team refuses at commit; a successor promoted by a move is
  notified and the transfer is logged like any other leadership change.
- Group deletion notifies every released member.
- The pick-that-team page is a paginated, filterable, sortable table
  built for thousands of listed teams, first come first served by
  default; with sequential reveal on, browsing guides no longer see the
  interest queue depth the leader cannot see.
- Every report download filename carries its generation moment; the
  attribute batch loader chunks its queries at scale.

## 1.12.0 (2026-07-27)

- Seat-plan fix: with the overlap tick off, a member whose value in ANY
  attribute was consumed by an earlier seat rule is excluded from later
  seats — after "2 with Department Computer", a third Computer student
  can no longer fill a distinct-sub-department seat.
- One vocabulary for composition: the page is "Composition", counting
  rules are "Counting rules" and the template is the "Seat plan" with
  "seat rules", in every heading, intro, label and clash message.
- The seat-rule form announces its mode: "Editing seat rule N" when
  editing, "Add seat rule" otherwise.
- The manager dashboard's state filter sits on one line with its button.
- Group size cells read plainly: "5 of exactly 5", "3 of 2 to 6,
  1 invited".
- Every report download is suffixed with its generation moment
  (_YYYYMMDDHHMMSS), so successive downloads never overwrite each other.

## 1.11.1 (2026-07-27)

- The team member drill-down shows first and last name separately, the
  mobile number beside its WhatsApp link, and one sortable, filterable
  column per composition dimension the activity uses, so a guide sees
  how each member satisfies the seat plan.
- The shared proposal is visible immediately on the review page: PDFs
  embed in place and images render in place, with the download link
  kept.

## 1.11.0 (2026-07-27)

- Pick that team: a leader may list their forming team, guides browse
  listed teams and express interest with rich-text remarks, and the
  leader accepts or rejects each interest, so the team always chooses
  its guide and the first contact stays inside Moodle. Accepting
  pre-assigns the guide, auto-declines every other pending interest,
  and the submitted team goes straight to that guide.
- Manager switches govern the flow: a leader-response window after
  which an interest times out and the guide may pick again, a cap on
  each guide's open interests, sequential first-come-first-served
  reveal of interests to the leader, and whether guides can see who
  else is interested. A pre-assigned guide can step out of a forming
  team, which relists it.
- The guide dashboard gained four stat cards with drill-downs: teams
  guided, interests awaiting a leader, timed out, and declined. The
  interest list opens each team's leader, topic and remarks, and its
  members with mail, WhatsApp and whole-team mail links.
- Formation analytics page for managers: funnel counts, median times
  from creation to submission and approval, listing to first interest,
  and interest to response, all exportable.
- The activity settings form is regrouped into named sections, the
  return comment is rich text, staged-move rule verdicts show their
  reason as visible text, and the plugin ships a real stylesheet with
  a distinctive card system.
- Interests are covered by privacy export and delete, backup and
  restore, and the events log; bulk mail tasks continue past a failing
  recipient and report what could not be sent.

## 1.10.0 (2026-07-26)

- The flagged report no longer issues thousands of queries on a large
  course. Per-group membership counts and composition compliance are
  now answered set-based: measured on 10,000 students in 2,000 groups,
  the anomaly scan fell from 4,002 queries to 6 and the compliance
  sweep from 10,001 to 6, with identical results.
- Bulk nudges are handed to a scheduled task instead of being sent
  inside the web request, and the defaulters tab gained a per-row
  action to place a student.
- The missing-attributes and group-anomaly lists became sortable,
  filterable, paginated tables, the last two plain lists in the plugin.
- The activity page's staff group panel is capped with a count and a
  link to the full listing rather than rendering every group.
- Validation messages for the membership minimum and the penalty fields
  render properly instead of showing the raw text in brackets.
- Guide picker labels are one translatable string, participant names
  follow the site's configured name format, and sorting is locale aware.
- Each tab of the flagged report explains itself instead of repeating
  the anomaly notice, and informational counters no longer look like
  disabled buttons.

## 1.9.0 (2026-07-26)

- Fixed the flagged report's defaulters tab, which failed with a
  database error both on screen and on download because its query
  carried one placeholder more than it supplied. A regression test now
  covers the tab.
- Every paginated listing gained a records-per-page selector (10 to
  200), remembered per user across pages and visits.
- The override form accepts a guide capacity of zero, the documented
  "always full" state that the rest of the plugin already honoured.
- Replaced three list scans that cost time proportional to the square
  of the class size, in the flagged report, the auto-grouping engine
  and the deadline reminder.
- Scale work for large cohorts: the nightly gradebook recomputation is
  batched instead of querying per student, roster and attribute
  downloads stream instead of loading whole datasets, historical
  auto-grouping exports chunk their lookups, and bulk nudges can be
  handed to an adhoc task rather than sent inside one web request.

## 1.8.3 (2026-07-26)

- The invite, nominate and submit forms on a group page each name their
  own action button, so every button id is both unique and the same on
  every visit. Previously the ids depended on which other forms the
  page happened to render.

## 1.8.2 (2026-07-26)

- Each form on a group page now renders its own action button id. All
  three previously used id_submitbutton, which is invalid HTML and made
  scripts and assistive technology address the wrong form.

## 1.8.1 (2026-07-26)

- The flagged report's explanation names all four download formats
  instead of only CSV.

## 1.8.0 (2026-07-26)

- The flagged report's guides tab shows each guide's current load beside
  their pending group, so managers can see which guide is the
  bottleneck, and the guide's name opens their full workload for the
  activity with its own multi-format export.
- Managers can message every listed defaulter, or every guide holding an
  overdue decision, in one confirmed action. Recipients are
  de-duplicated, so a guide with several overdue groups receives a
  single message naming the count.
- Guides can switch their notifications to a daily or weekly digest
  instead of one message per group. The preference is per person and
  off by default; queued items are covered by the privacy provider and
  by backup and restore.
- New manager page listing every auto-grouping run with its outcome and
  decision log, exportable both as run summaries and as one row per
  formed group.

## 1.7.1 (2026-07-26)

- Fixed the 1.7.0 upgrade: the new volunteer table declared an index on
  userid that its own foreign key already provides, which stopped the
  upgrade on existing sites. Fresh installations were unaffected.
- Restores the userid index on sites upgraded through the interim
  build, so installed and upgraded schemas match exactly.

## 1.7.0 (2026-07-26)

- New optional setting, guide volunteering: when enabled, a guide only
  becomes available for new assignments after declaring a capacity, up
  to the activity's maximum groups per guide. Guides who have not
  volunteered, or who have volunteered for zero groups, are
  unavailable for new assignments; every existing enforcement point
  and picker inherits this through the override resolver, so an active
  manager guide-scope override always takes precedence over the
  volunteered number.
- Reducing a volunteered capacity below the guide's current load never
  unassigns anything, matching the plugin's established grandfathering
  pattern; the guide dashboard shows the grandfathered note when this
  applies.
- The guide dashboard gained a small form for declaring or updating a
  capacity, with a success notification and a refusal when the chosen
  number is out of range.
- Volunteered capacity is personal data: covered by the privacy
  provider (metadata, export, all delete paths) and by backup/restore.
- Manager override target pickers still list guides who have not
  volunteered, so a manager can grant such a guide capacity directly.

## 1.6.2 (2026-07-26)

- The flagged report's defaulters, guides and quota tabs are rebuilt
  on Moodle's flexible_table/table_sql instead of hand-rolled sort
  links and PHP array sorting, giving them the native table look and
  SQL-side sorting and paging.
- The guides-pending tab joins the guide's name in the same query
  instead of one core_user::get_user() call per row.
- The students tab, its mustache template and the multi-format export
  flow (ODS/Excel/CSV/TXT, admin default, raw values) are unchanged.

## 1.6.1 (2026-07-26)

- Exports feed raw values to the dataformat writers, so names such as
  O'Brien or R&D no longer arrive double-encoded in spreadsheets.
- The notification-preferences page names the automatic-grouping
  message provider instead of showing a placeholder.
- Automatic-grouping notifications are sent only after the database
  transaction commits.
- Guide reminder markers are reset through the preferences API so no
  stale cached marker survives.
- Export file names pass through clean_filename() and the guide
  dashboard's date filter rejects impossible calendar dates.

## 1.6.0 (2026-07-25)

- Composition-template slots can be edited in place, not just added
  and deleted.
- Report downloads (flagged tabs, guide dashboard) moved to Moodle's
  dataformat writers and now offer OpenDocument, Excel, CSV and
  tab-separated TXT, with the default format chosen by the site
  administrator in the new plugin settings page; the earlier CSV
  encoding problem in Excel is gone.
- The flagged report's tabs gained a name filter and sortable column
  headers alongside the existing pagination.
- The guide dashboard's approved-since filter uses a native calendar
  date input and the server validates the date before applying it.
- The quota-rules and composition-template introductions now explain
  the difference between counting rules and the seat plan.

## 1.5.2 (2026-07-25)

- Auto-grouped students are notified of their placement and managers
  receive the run summary (new autogroupresult provider).
- Guide reminder markers reset when a fresh review cycle starts, so
  resubmitted groups get their 50%/90% reminders again.
- The auto-approval sweep defers (with a log) when its recorded relief
  lands on a pending guarded override instead of approving unexplained.
- Auto-formed group names use a monotonic sequence and a localised
  date; an uninstall notice states that frozen core groups remain.

## 1.5.1 (2026-07-25)

- Auto-approval past the guide window now records any size or quota
  relief as a group-scope override before approving, stamps the event
  as automatic and notifies managers; guides receive escalating
  reminders at 50% and 90% of their decision window.
- The privacy provider exports and deletes proposal documents.
- Auto-grouping is a three-state mode (off, manual only, manual and
  automatic at the cutoff); previously enabled activities migrate to
  the full mode. Auto-formed group names are sequence-unique.

## 1.5.0 (2026-07-24)

- Attribute CSV import gains update modes (override with file, or fill
  missing only) plus admin default values for empty cells, both shown
  in and surviving the mandatory preview.
- Guide picker overrides: hide a capability holder from every guide
  picker, or set an explicit guiding cap of 0 (visible, always full).

## 1.4.3 (2026-07-24)

- Flagged report reorganised into paginated tabs (students/anomalies,
  defaulters, guides pending with overdue deadlines, quota-failing
  groups), each downloadable as CSV; composition clash detector on
  the quota page.

## 1.4.2 (2026-07-24)

- Ineligible invitation candidates stay listed with their refusal
  reason; leaders edit title and brief while forming; slot template
  form uses the vocabulary picker; guide dashboard date filter is
  timezone-safe with a CSV download; core grading section (category,
  grade to pass) replaces the bare grade field.

## 1.4.1 (2026-07-24)

- External audit fixes: cross-group cap checks race-safe under the
  activity lock, guarded-override rechecks fire on move commits and
  nightly, course reset support, per-row CSV length guards, defaulter
  penalties honour date overrides, core group names clamped, and more.

## 1.4.0 (2026-07-24)

- Guide decision window with an auto-approve switch: submitted groups
  undecided within the window are automatically counted as accepted.
- Minimum memberships per student: defaulters are listed on the
  flagged report and penalised per missing group after the due date.
- Incomplete-group penalty with a teacher-set leader majority share.
- Sequence-of-joining gradebook decomposition: guide-awarded group
  marks and every penalty bind to the student's groups in joining
  order with stepwise clamping; the full breakdown is published as
  gradebook feedback.

## 1.3.0 (2026-07-24)

- Slot-based composition templates (booked members, value/distinct
  matches, overlap control) gating compliance with the classic rules.
- Programme attribute with its own vocabulary; admin-level CSV ingest
  now auto-creates missing departments, sub-departments and
  programmes; downloadable blank CSV templates per programme.
- Project proposal upload with a per-activity mandate; guide rich-text
  notes on the review page.
- Defined behaviour for size changes after freezing: grandfathered
  groups, flagged-report listing.

## 1.2.0 (2026-07-24)

- Guarded reduction overrides (pending until blockers clear), explicit
  leader-replacement consent on moves, AJAX user selectors at scale,
  guides and roster tables, seat location attribute, bulk department
  updates.

## 1.1.0 (2026-07-24)

- Pre-defined departments: a site-wide category tree in the
  course-categories format (multiple levels) now backs the department
  and sub-department attributes; drop-down selection in the editor,
  strict validation in the CSV importer, automatic seeding from
  existing data on upgrade, and a new admin page to curate the tree.
- Notification templates: a per-activity *Notification templates* page
  lets editing teachers customise the subject and body of every
  message kind, with reset-to-default; overrides are included in
  course backups.
- Wording: numeric ranges are written in words ("2 to 4") instead of
  an en dash.

## 1.0.1 (2026-07-24)

- Notification templates: every message now receives the recipient
  placeholders `{$a->firstname}`, `{$a->lastname}`, `{$a->fullname}`
  and `{$a->url}`, enabling full personalisation through Moodle's
  Language customisation. Invitations additionally expose
  `{$a->expirynote}` and the invitation body was rewritten to address
  the invitee by name, state the expiry date and explain the
  forming-vs-frozen change rules.

## 1.0.0 (2026-07-24)

First stable release. Complete implementation of the binding
specification: invitation-only group formation with reserved seats and
acceptance cascades; five override-resolved numeric limits with
position displays and reasons; leadership succession; guide review
with load limits; plugin-local participant attributes with admin CSV
ingest; prioritised composition quotas with a live deficiency panel;
the central override subsystem (P1–P16 precedence, fully tested);
transactional jointly-validated staged moves; the per-group penalty
ledger and cumulative gradebook integration; freeze/unfreeze with
snapshots, drift reporting and bulk operations; deterministic
auto-grouping with rule relaxation; flagged reports; events,
messaging, scheduled tasks, privacy API and backup/restore.
Built slice-by-slice with per-slice PHPUnit + Behat gates on both
MySQL/MariaDB and PostgreSQL (audit trail in docs/audits/).
