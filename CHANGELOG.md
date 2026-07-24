# Changelog

## 1.2.0 (2026-07-24)

- Guarded reductions: an override that reduces a cap below the
  target's current position parks as *pending* with a blocker list
  (each linking to the page that resolves it) and activates
  automatically once cleared and re-checked; pending overrides never
  affect the resolver.
- Deliberate leadership changes: staging a move that makes someone
  leader of an already-led group requires an explicit "Replace the
  current leader" consent (LEADR verdict, not code-bypassable; leader
  swaps in one set exempt themselves); the demoted leader is notified.
- Move form at scale: student and successor use the AJAX user search
  (five-thousand-student courses), the successor is validated
  server-side as a confirmed member of the source group, and the
  bypass checkboxes are labelled in words.
- Manager dashboard split: new sortable/filterable/downloadable
  Guides table (department, sub-department, seat location, live load)
  and leader/member Roster table; guide loads left the dashboard.
- Faculty seat location: new participant attribute (editor + optional
  "Seat Location" CSV column), shown on the guides table.
- Departments: bulk update by pasting "/"-separated paths.

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
