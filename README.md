# Group self-selection (Advanced) — `mod_selfselectadvanced`

[![Moodle Plugin CI](https://github.com/Resources4Moodle/moodle-mod_selfselectadvanced/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/Resources4Moodle/moodle-mod_selfselectadvanced/actions)

A Moodle activity module for constraint-governed lab-group formation.
Students self-organise into groups under teacher-defined limits and
composition quotas; a project guide reviews, approves and freezes each
group; frozen groups become Moodle core course groups that any
downstream activity can use.

## Requirements

**Moodle 5.2.1 or later in the 5.2 series, on PHP 8.4 or later ONLY.**
MariaDB or PostgreSQL.

That is a promise narrowed on purpose, not by drift. The plugin was
previously declared for "4.5 LTS or 5.x", but it is developed, gated and
CI-tested against Moodle 5.2 on PHP 8.4 and nothing else, and promising
four branches while verifying one is a claim the project cannot stand
behind — the same reasoning `version.php` itself records beside
`supported = [502, 502]`. `requires = 2026042001` is the **5.2.1** serial —
5.2.0 is `2026042000` — so the floor is 5.2.1, not every 5.2 site.

The PHP 8.4 floor is asserted at runtime on install and on upgrade
(`db/install.php`, `db/upgrade.php`), because Moodle's `version.php` format
has no field for a PHP minimum. **Stated precisely:** those are the plugin's
own hooks, and `xmldb_selfselectadvanced_install()` is a POST-install hook —
core creates the `db/install.xml` schema before calling it. So on a site below
the floor the install is refused, but not before the tables are made. Earlier
wording here claimed the refusal came "before anything is created"; that was
wrong (external audit FCA-001, 2026-08-13). Expressing the floor as a real
environment requirement, which core evaluates before installing anything, is
owed.

## Features

- **Invitation-only formation** with reserved seats: pending
  invitations occupy seats, so a group can never overshoot its maximum
  when several invitees accept at once; acceptance is atomic and
  reaching one's membership cap auto-declines other pending offers.
- **Five numeric limits** (min/max group size, max groups led, max
  memberships, max groups guided), enforced at every gate with the
  current position always displayed ("You lead 1 of 2 groups") and the
  reason shown on every disabled control.
- **Candidate search across every core name field** — first, last,
  middle and alternate name — through the core autocomplete, drawing
  from the whole course enrolment. It has never matched a phone number,
  and it no longer matches an email address either, for any role (see
  *Privacy*).
- **Guide search by name, employee id or email address**, on the guide
  pickers only: a student who approached a faculty member in person
  types whichever detail they came away with. The address arm engages
  only for a query containing `@`; without one the pickers match names
  and nothing else. No picker, page, export or web service of this
  plugin ever *renders* a guide's address (see *Privacy* for what that
  does and does not promise).
- **Leadership succession**: leader-nominated transfer or step-out
  with confirmation, atomic lead-cap re-checks and a minimum-size
  replacement rule.
- **Guide review**: submit → approve (irreversible) / return with a
  mandatory comment; per-guide load limits with live "Guiding x of y"
  figures; manager-assigns mode with an assignment queue.
- **Plugin-local participant attributes** (gender, department,
  sub-department, mobile) ingested by site administrators via CSV with
  a dry-run validation report — never touching user profiles; the
  plugin never creates user accounts.
- **Composition quotas** with manager-set priority and a live
  deficiency bucket panel; compliance gates submission, approval and
  freezing.
- **A single override subsystem**: user/group/guide/move-scope
  overrides for dates, all five limits, quota exemptions and penalty
  waivers, resolved through one service with a fully tested precedence
  matrix.
- **Transactional staged moves**: pending until a manager commits a
  jointly-validated set (a swap commits as one action); per-move rule
  bypasses are explicit, logged overrides.
- **Per-group penalty ledger** feeding one gradebook item (cumulative
  deduction per membership, floored at zero, null until placed);
  groups approved within an overridden window incur no penalty.
- **Freeze/unfreeze**: mirrored core groups in an activity grouping,
  roster snapshots, drift detection (out-of-band core edits are
  reported and discarded, never merged), restriction warnings, bulk
  freeze with filters.
- **Deterministic auto-grouping** of groupless students at their
  effective cutoff, with priority-ordered rule relaxation, seeded
  replay, and manager placement of residue via override-backed moves.
- Full events, messaging with deep links, scheduled tasks, privacy
  API and backup/restore. The interface is built to avoid conveying
  meaning by colour alone; it has not been through a formal WCAG
  audit, and that claim is not made for it.

## Installation

Copy to `public/mod/selfselectadvanced` (Moodle 5.x split layout) and
run the upgrade. No configuration is required; all settings are per
activity instance.

## Capabilities

| Capability | Default archetypes |
|---|---|
| `mod/selfselectadvanced:addinstance` | editingteacher, manager |
| `mod/selfselectadvanced:creategroup` | student — create a new group |
| `mod/selfselectadvanced:lead` | student — act as leader of an existing group |
| `mod/selfselectadvanced:respond` | student |
| `mod/selfselectadvanced:guide` | teacher (non-editing) |
| `mod/selfselectadvanced:freeze` | teacher (non-editing) |
| `mod/selfselectadvanced:unfreeze` | editingteacher |
| `mod/selfselectadvanced:manage` | editingteacher |
| `mod/selfselectadvanced:managecomposition` | editingteacher (and every role holding `:manage`, on upgrade) |
| `mod/selfselectadvanced:assignguide` | editingteacher (and every role holding `:manage`, on upgrade) |
| `mod/selfselectadvanced:override` | editingteacher |
| `mod/selfselectadvanced:ingestattributes` | none (system context; site admins) |
| `mod/selfselectadvanced:viewall` | editingteacher (the non-editing teacher was removed in 1.20.1 — **on fresh installs only**; no existing site loses it) |
| `mod/selfselectadvanced:viewassignedteams` | teacher (non-editing) — open the team pages of teams they are the assigned guide of, and only those |
| `mod/selfselectadvanced:viewparticipantidentity` | none (granted deliberately) — see participants' identity and mobile columns inside this activity; AND-ed onto the core identity capabilities, never a substitute for them. Since 1.20.1 it does **not** reopen an email address while contact privacy is on: nothing does |

Every action checks the capability, never the role name.

### Pausing new group creation without stranding leaders

Release 1.20.26 separates `mod/selfselectadvanced:creategroup` from
`mod/selfselectadvanced:lead`. On upgrade, Moodle clones each role's recorded
`:creategroup` permission — including ALLOW, PREVENT and PROHIBIT — to `:lead`,
so the upgrade itself does not change who may lead an existing group.

After upgrading, an administrator who wants to pause **new** group creation can
prohibit `mod/selfselectadvanced:creategroup` at the activity while leaving
`mod/selfselectadvanced:lead` unchanged. Existing leaders can then continue to
invite members, edit, submit and otherwise run their groups. The activity's
`timeopen` / `timecutoff` formation window remains the normal date-based
control; the capability split is for role- or activity-specific permission
policy and does not add a duplicate setting.

## Admin walkthrough (one full lifecycle)

1. *Site administration → … → Group self-selection (Advanced) →
   Departments and sub-departments*: define the department tree, then
   under *Participant attributes* upload the CSV
   (`Username, First name, Last Name, Gender, Department,
   Sub-Department, Mobile Number`, optional `Email` fallback key).
   Unknown users are rejected — create accounts through standard
   administration and re-run. Preview first; nothing is written until
   you confirm.
2. Add the activity to a course; set sizes, caps, guide mode, the
   formation window and the penalty scheme.
3. Managers add quota rules (values offered come from the ingested
   data) and, where needed, overrides.
4. Students create groups and invite peers (search by name — never by
   address); invitees accept or decline from their landing page.
5. Leaders submit to a guide, found by name, employee id or a typed-in
   email address; guides approve or return with comments;
   approved groups accrue any late penalty into the ledger and
   gradebook.
6. Guides freeze (single or bulk with filters): each group becomes a
   core course group inside the "Self-selection: …" grouping.
   Managers unfreeze; the roster is restored exactly as frozen.
7. At the cutoff, auto-grouping sweeps groupless students; residue
   appears on the flagged report for override-backed placement.

## Customising notification texts

Two layers, both using the same `{$a->name}` placeholder syntax:

- **Per activity (editing teachers):** the activity's *Notification
  templates* page (activity settings menu, or the manager dashboard)
  lists every message kind the activity sends; anyone with
  `mod/selfselectadvanced:manage` can replace its subject and body for
  that activity, and reset back to the default at any time. These
  overrides travel with course backups.
- **Site-wide (administrators):** every default is a language string,
  rewritable without code via *Site administration → Language →
  Language customisation* (component `mod_selfselectadvanced`, keys
  `msg…subject` / `msg…body`).

Besides each message's own placeholders (such as `{$a->group}`,
`{$a->activity}`, `{$a->pluginuid}`), **every** template may use the
standard recipient placeholders `{$a->firstname}`, `{$a->lastname}`,
`{$a->fullname}` and `{$a->url}` (deep link to the relevant page).
Invitation messages additionally get `{$a->expirynote}` — an
"expires on …" sentence when the activity sets an invitation expiry,
empty otherwise (the sentence itself is the customisable string
`msginvitationexpirynote`).

## Pre-defined departments

*Site administration → Plugins → Activity modules → Group
self-selection (Advanced) → Departments and sub-departments* holds the
allowed vocabulary for the department attributes, organised exactly
like course categories (a tree; multiple levels are possible, the
attribute fields use the first two). While the tree is empty,
department and sub-department stay free text; once the first category
exists, the attribute editor switches to drop-down lists and the CSV
importer rejects rows whose values are not in the tree. Upgrading
sites get the tree seeded automatically from already-ingested values.

## Composition templates (slots)

Beyond the classic per-value quota rules, the quota page defines an
ordered **slot template**: each slot books `n` members whose
department, sub-department, gender or programme either share a value
("2 members with department Computer", or "2 from any ONE department")
or are pairwise distinct ("3 members each from a distinct
department"). A member is booked into at most one slot, so
requirements adjust as people are booked; values consumed by earlier
slots are excluded from later ones unless the slot allows overlap —
giving must-match and must-not-match in one mechanism. Compliance
gates submission, approval and freezing together with the classic
rules.

## Attributes vocabulary and templates

Departments/sub-departments (a course-category-style tree, any depth)
and programmes are managed on the admin pages — or **created
automatically by the CSV ingest**, which runs at admin level: unknown
values are added to the vocabulary with a warning, never rejected.
Blank CSV templates (one per programme) pre-filled with the drilled
tree are downloadable from the Participant attributes page, so every
office fills the same shape. Attributes live centrally (site-wide):
enrolling a student in any course makes them readable there
immediately — no re-ingest.

## Proposals and guide notes

Each activity decides (settings checkbox) whether a written **project
proposal** must be uploaded before a group can be submitted; the
leader uploads one document on the group page, guides read it from the
review page, and it travels with course backups. Guides keep private
**rich-text notes** on the review page before accepting a group;
students never see them.

Who may DOWNLOAD a proposal is one rule, asked both by the screen that
offers the link and by the file server that answers it (1.20.1): the
team's **confirmed** members, the team's **assigned guide**, a holder
of `:viewall` or `:manage`, and a guide the team is **currently
approaching** for as long as that approach is unanswered. An invited
but unconfirmed person sees the filename and no link — an invitation is
not yet a membership. Before 1.20.1 the file server asked a different
question from every other door on the team: an assigned guide whose
site had withdrawn `:viewassignedteams` still passed it, and a
`:manage` holder who could open the review page was refused the file
that page embeds.

## The flagged report: anomalies and grandfathered groups

The flagged report is the manager's worklist, in tabs (each
downloadable as CSV): students in no group, individual defaulters
(below the minimum memberships), guides with pending decisions
(deadline and overdue marker when a decision window is set), groups
failing the composition requirements, and **group anomalies**. An
anomaly is a group in an impossible or unowned position: *leaderless*
groups (e.g. after a privacy deletion removed the leader) and
**grandfathered** groups — groups approved or frozen under earlier
limits that a later settings change has left outside the current
minimum/maximum. Grandfathering means the plugin never reshapes or
punishes such groups automatically: they keep the position they
legitimately earned, and the report exists so a manager resolves each
one deliberately — unfreeze it, stage moves, or grant an override.

## Changing limits mid-course

Changing sizes or caps later never reshapes existing groups: approved
and frozen groups are **grandfathered** exactly as they stand, and any
group left outside the new limits is listed on the flagged report for
a manager to resolve deliberately (unfreeze, moves or overrides).
Reduced caps interact with the guarded-override mechanism the same
way: nothing is enforced retroactively.

## Privacy

The privacy provider exports and deletes memberships, briefs,
invitations, nominations, overrides, moves, penalties, reminder
preferences and the plugin-local participant attributes (stored
site-wide, solely for grouping, never written to user profiles).
Candidate search no longer matches on email for every inviter — that
was true up to 1.19.x and was changed in 1.20.0, because gating the
DISPLAY of an address while leaving the MATCH open leaves an oracle:
type an address in, and whether a row comes back answers whose it is.
The match and the label sit on the same gate.

**Since 1.20.1 that gate has no exemptions for a PARTICIPANT's
address.** Whatever the contact-privacy setting, no picker of this
plugin matches a participant's email address, and while the setting is
on none renders, exports or labels one, for **any** role — student team
leader, coordinator, non-editing teacher, editing teacher, manager or
site administrator alike. Up to 1.20.0 a `:manage` holder searched
and saw addresses through the switch on the candidate picker, and the
staff move form's participant picker matched on the address for them
regardless of the switch; the move form's picker is now names-only
unconditionally, matching the expression-of-interest roster, which has
been names-only for everybody since 1.20.0. Neither picker has ever
returned a phone number. Staff reach a participant with **Send a
message** instead.

The two staff **imports** are the deliberate exception, and the reason
is that the address there is one the operator typed: the coordinator
upload and the participant-attribute CSV both accept an address as a
fallback match key for a row whose username is blank, resolved once by
exact equality. Neither ever puts an address back into its report — a
matched person is named by full name and username, and an unmatched
line echoes only the key the file supplied.

**One further carve-out, and it is about the SUBJECT rather than the
viewer (1.20.2, maintainer decisions 32 and 41).** The **guide**
pickers — and only those — match the typed text against a guide's own
email address as well as their name, **and only when that text contains
an `@`**. Type anything without one and the pickers match names alone,
exactly as they did before. It exists because a student approaches a
faculty member in person and comes away with an address or an employee
id: the id is recorded as the surname and so already matched, the
address did not.

**What this does and does not promise, stated plainly, because this
section has twice described a matcher the code did not have.** A
substring match leaks the string it matches: with the address arm
unconditional, a plain enrolled student recovered a whole guide address
— a local part with no relation to the guide's name — in 453 picker
calls, extending a matched fragment one character at a time on
found/not-found alone. Requiring an `@` would not have closed that; a
prober can anchor on the `@` and grow outwards, and this section said
so while accepting the residue.

**That is history. The code now matches an address by EXACT,
case-insensitive EQUALITY**, and the address arm engages only when the
whole query is a syntactically valid address (core's `validate_email()`).
A partial address matches nothing and falls through to name matching, so
there is no found/not-found gradient left to climb and the 453-call
oracle is closed rather than accepted. The address column is selected
from the database only when the query could use it. This paragraph
claimed the opposite until 2026-08-13, when an external audit (DOC-002)
pointed out that the documentation was describing a live oracle the
implementation had already removed.

What *is* absolute:

- the pool is the holders of `mod/selfselectadvanced:guide` in that
  module context — staff, being approached, not protected
  participants. A student's own address reaches nobody through a guide
  picker, whole or by domain;
- **nothing renders an address.** No picker, page, export, CSV, web
  service, notification or event payload of this plugin displays or
  links a guide's address; the row the search hands its caller has no
  address field on it at all. The search returns a guide's name,
  department, sub-department and (where entitled) load, exactly as
  before;
- the column is not even fetched unless the typed text contains `@`. The
  condition is the **query**, not the caller: a screen that offers a
  search box can fetch an address when someone types one with an `@` in
  it, and the contact page — where a team leader looks for a guide — is
  one of those. Screens that pass no query at all, such as the guide
  queue, the unfiltered loads tab and every "who is selectable" check,
  never load one;
- the **participant** surfaces are untouched, in **both** states of the
  contact-privacy switch: the candidate picker, the staff move form's
  participant picker and the expression-of-interest roster still match
  on names alone, for everybody. That is the oracle rule and it has not
  moved.

### Contact privacy (per activity, default on)

`contactprivacy` is an activity setting, on for new instances and for
every instance that existed before 1.20.0, switched by a `:manage`
holder. While it is on:

- no page, export, CSV, picker, web service, notification or event
  payload of this plugin renders, links or **matches on** a
  **participant's** email address, for **anybody** — `:manage` holders,
  editing teachers and administrators included (1.20.1; up to 1.20.0
  `:manage` was exempt). Staff reach a participant with **Send a
  message**, which travels as a Moodle message and shows the sender no
  address. Two exceptions, both narrow: a staff import, where the
  address is supplied by the operator's own file and never appears in
  the report; and the **guide** pickers, which MATCH a guide's own
  address — only for a query containing `@` — so that a student can
  find the faculty member they approached, and render none (decisions
  32 and 41, above, with the residual probe stated there);
- a mobile number reaches only a viewer *connected* to its owner — a
  confirmed teammate, the guide assigned to that person's team, or the
  holder of that person's claimed request ticket — and only when the
  owner switched their own sharing consent on. Nobody below a site
  administrator bypasses that consent: the bypass asks for
  `mod/selfselectadvanced:viewparticipantidentity`, which no role holds
  by default.

Backups exclude auto-grouping logs, pending staged moves and the queued
digest notifications (operational/transient state; the digest queue
joined the exclusions in 1.20.4 — a restored queue row carried a deep
link to the original activity and payload text resolved on the source
site). Uninstalling drops the plugin's own
tables, configuration and capabilities. Two things are deliberately
left behind: previously frozen core course groups, which are course
data by then, and the Group Coordinators role when anybody is still
assigned to it at that moment — an uninstall must not revoke people's
access as a side effect. A role that is already unassigned is removed,
and whatever is kept is named on screen at the time. Since 1.20.0,
coordinator appointments are recorded against the activity, and Moodle
deletes those activity contexts *after* this plugin's own uninstall
step has run: on a site whose only appointments were made by this
plugin, the role is therefore kept and then ends up with nobody in it.
It grants nothing to nobody, and an administrator can delete it.

## Third-party libraries

**None.** The UI is built entirely from Moodle core components: core
forms and the core autocomplete, core table machinery, Mustache
templates with Bootstrap utility classes, core AMD (`core/ajax`) with
four thin transport modules - one per searchable picker (teams,
guides, candidates, participants), each of which does nothing but hand
a query to a web service and pass the results back.

## Documentation

`docs/architecture.md` (binding plan), `docs/reviews/` (gate reviews),
`docs/audits/` (one written audit per slice plus the final report).

## License

GPL v3 or later · Copyright 2026 JSP <jsp@jsp.net.in>
