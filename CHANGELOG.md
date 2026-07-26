# Changelog

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
