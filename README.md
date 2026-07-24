# Group self-selection (Advanced) — `mod_selfselectadvanced`

A Moodle activity module for constraint-governed lab-group formation.
Students self-organise into groups under teacher-defined limits and
composition quotas; a project guide reviews, approves and freezes each
group; frozen groups become Moodle core course groups that any
downstream activity can use.

## Requirements

- Moodle 4.5 LTS or 5.x · PHP 8.1+ · MySQL/MariaDB or PostgreSQL
  (equal support; XMLDB only)

## Features

- **Invitation-only formation** with reserved seats: pending
  invitations occupy seats, so a group can never overshoot its maximum
  when several invitees accept at once; acceptance is atomic and
  reaching one's membership cap auto-declines other pending offers.
- **Five numeric limits** (min/max group size, max groups led, max
  memberships, max groups guided), enforced at every gate with the
  current position always displayed ("You lead 1 of 2 groups") and the
  reason shown on every disabled control.
- **Candidate search by first name, last name or email** through the
  core autocomplete, drawing from the whole course enrolment.
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
  API, backup/restore, WCAG-conscious UI (never colour alone).

## Installation

Copy to `mod/selfselectadvanced` (4.x) or `public/mod/selfselectadvanced`
(5.x split layout) and run the upgrade. No configuration is required;
all settings are per activity instance.

## Capabilities

| Capability | Default archetypes |
|---|---|
| `mod/selfselectadvanced:addinstance` | editingteacher, manager |
| `mod/selfselectadvanced:creategroup` | student |
| `mod/selfselectadvanced:respond` | student |
| `mod/selfselectadvanced:guide` | teacher (non-editing) |
| `mod/selfselectadvanced:freeze` | teacher (non-editing) |
| `mod/selfselectadvanced:unfreeze` | editingteacher |
| `mod/selfselectadvanced:manage` | editingteacher |
| `mod/selfselectadvanced:override` | editingteacher |
| `mod/selfselectadvanced:ingestattributes` | none (system context; site admins) |
| `mod/selfselectadvanced:viewall` | editingteacher, teacher |

Every action checks the capability, never the role name.

## Admin walkthrough (one full lifecycle)

1. *Site administration → Plugins → Activity modules → Group
   self-selection (Advanced) → Participant attributes*: upload the CSV
   (`Username, First name, Last Name, Gender, Department,
   Sub-Department, Mobile Number`, optional `Email` fallback key).
   Unknown users are rejected — create accounts through standard
   administration and re-run. Preview first; nothing is written until
   you confirm.
2. Add the activity to a course; set sizes, caps, guide mode, the
   formation window and the penalty scheme.
3. Managers add quota rules (values offered come from the ingested
   data) and, where needed, overrides.
4. Students create groups and invite peers (search by name or email);
   invitees accept or decline from their landing page.
5. Leaders submit to a guide; guides approve or return with comments;
   approved groups accrue any late penalty into the ledger and
   gradebook.
6. Guides freeze (single or bulk with filters): each group becomes a
   core course group inside the "Self-selection: …" grouping.
   Managers unfreeze; the roster is restored exactly as frozen.
7. At the cutoff, auto-grouping sweeps groupless students; residue
   appears on the flagged report for override-backed placement.

## Privacy

The privacy provider exports and deletes memberships, briefs,
invitations, nominations, overrides, moves, penalties, reminder
preferences and the plugin-local participant attributes (stored
site-wide, solely for grouping, never written to user profiles).
Candidate search matches on email for all inviters — a deliberate,
documented decision (display of addresses remains identity-gated);
search-by-email confirms at most that an address belongs to a course
peer. Backups exclude auto-grouping logs and pending staged moves
(operational/transient state). Uninstalling removes all plugin data
but leaves previously frozen core course groups in place — by then
they are course data.

## Third-party libraries

**None.** The UI is built entirely from Moodle core components: core
forms and the core autocomplete, core table machinery, Mustache
templates with Bootstrap utility classes, core AMD (`core/ajax`) with
one thin transport module.

## Documentation

`docs/architecture.md` (binding plan), `docs/reviews/` (gate reviews),
`docs/audits/` (one written audit per slice plus the final report).

## License

GPL v3 or later · Copyright 2026 JSP <jsp@jsp.net.in>
