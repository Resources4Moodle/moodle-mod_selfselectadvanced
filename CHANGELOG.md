# Changelog

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
