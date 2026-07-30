# 1.17.0 — work order of 2026-07-30

Thirteen items from the maintainer after reviewing the 1.16.0 deck and
the live site, plus one substantial new feature. Two answers settled by
the maintainer before work started:

- The teacher's pattern governs the **project ID**, not the group name.
  The group-name format shipped in 1.16.0 was a terminology error and is
  **removed**; course-wide name **uniqueness** stays, being separately
  useful.
- Students-approach mode becomes the **default for new activities**, and
  the guide-side settings move to the bottom of the form. Existing
  activities are untouched.

## A. Corrections to what 1.16.0 shipped

**A1 — project ID pattern replaces the name format.** `nameformat` and
`nameformatexample` go; a new `uidformat` takes a template built from
`{prefix}`, `{course}` and `{number}`, defaulting to the current
`{prefix}-{course}-{number}` so every existing site keeps the ids it
already issues. `groups::build_pluginuid()` renders the template;
validation refuses a template without `{number}` (ids must stay unique)
and refuses unknown placeholders. Ids already issued are never rewritten,
as before.

**A2 — students-approach is the default and comes first.** The settings
form puts it at the top of the Guides section; team listing and guide
volunteering move to the bottom of the form. `studentapproach` defaults
to 1 for new activities; the upgrade leaves existing rows at their
current value.

**A3 — a coordinator does not police their own requests.** A ticket
filed by the viewer is hidden from their own queue. They are already
refused if they try to claim it; hiding it removes the invitation to try
and the appearance that it is theirs to work.

## B. Completing the coordinator role

**B1 — coordinators may set overrides, except where they are involved.**
The role gains `mod/selfselectadvanced:override`; `overrides.php` and the
override service refuse a coordinate-only actor on any group they guide,
are nominated to guide, or belong to, and on any override whose subject
is themselves. Managers stay exempt, as with every other conflict rule.

**B2 — a coordinator dashboard.** A page of their own, reachable from
the activity: the teams they may act on, the ticket queue, the overrides
they may set, and what is waiting. Manager-only tools stay on
`manage.php`.

**B3 — bulk coordinator ingest.** An editing teacher uploads a CSV of
users. Rules from the order:
- a person must already be enrolled in the course to be made a
  coordinator; enrolling them is an **optional** switch on the form,
  as is unenrolling on removal;
- **add and remove exactly what the file names**, leaving everyone else
  alone; or **overwrite**, so the file becomes the complete list.
Every add and every removal is logged as an event.

## C. Administering 1500+ groups

**C1 — the guide-assignment page in tabs.** Today the first table
paginates, filters and sorts; the reassignment table and the guide-load
list below it do neither, and at 1500 groups they are unusable and easy
to miss. All three become tabs, each a `table_sql` with per-column sort,
filters and paging — the shape the flagged report already uses.

**C2 — group anomalies get their own tab** in the flagged report rather
than sharing the students tab.

## D. Notification templates

Every template is rewritten to read like a message a person would send:
a subject that says what happened, a short opening line, the key facts
set apart rather than buried in prose, and a signature line naming the
activity and course. Templates are added for the actions that have none
(coordinator assignment and removal, guide contact, contact accepted and
declined).

## E. Contacting a guide without exposing anyone's address

The workflow the order describes, built on **new pages** so the existing
submission and review pages are undisturbed.

- A team may contact up to a set maximum of guides (activity setting).
- The guide list shows name, department, sub-department and current
  load. No email address appears anywhere, for either party.
- Contacting sends a **templated** message through Moodle's own
  messaging - the plugin never handles or displays an address.
- The guide follows a link to a new page, reads the team's proposal, and
  accepts or declines, with or without a reason.
- Accepting assigns them as the team's guide; declining frees nothing by
  default (the attempt is spent) but the team may contact another guide
  while under the maximum.

New table `selfselectadvanced_contact`; new pages `contact.php` (team
side) and `contactreview.php` (guide side).

## F. Demonstration and deck

- A demonstration activity restoring the original condition: teams of
  **exactly five**, except where an override says otherwise.
- The deck loses the formation-analytics material, and gains the
  coordinator ingest, the coordinator dashboard, the tabbed
  large-group administration, and the contact-a-guide workflow.

## What the round found

Two defects of the same shape as ones this plugin has already had, both
caught by its own gate rather than by review:

- **A new guard removed authority that already existed.** The override
  conflict rule was applied to everyone without the manage capability,
  so an editing teacher or a guide setting an override on a team they
  guide was refused - something both could do before. This is exactly
  the mistake `freeze::unfreeze()` made in 1.16.0, one release later in
  a different place. A guard must restrain only the NEW authority:
  gate it on the capability the new role carries.
- **A database change was added to a version already installed.** The
  contact table and its setting went into the upgrade step for a
  version the test environments had built from, so the step never ran
  and the schema was missing the table. The same applies to the
  coordinator role's new capability, which lives in a list nothing
  re-runs: the upgrade has to re-assert the role for an existing site
  to receive it. Any new db artefact needs its own version.

And one that only a screenshot could show: three language strings did
not exist, so the pages rendered `[[department]]`, `[[subdepartment]]`
and `[[proposalnone]]`. Nothing fails on a missing string - it simply
reads as nonsense to whoever is looking.

## Answers recorded for the maintainer

- **Overrides by coordinators** did not exist before this round: the
  capability is editing-teacher only and the role never held it (B1).
- **The audit trail exists and is thorough** - 34 event classes covering
  overrides, tickets, the group lifecycle, staged moves and limit
  changes, all in Moodle's standard log. What is missing is a readable
  view inside the plugin; the coordinator dashboard (B2) surfaces the
  part a coordinator needs.
- **There was no coordinator dashboard** before this round (B2).
